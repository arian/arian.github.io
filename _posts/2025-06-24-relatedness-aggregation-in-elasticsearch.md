---
title: Relatedness Aggregation in Elasticsearch
---

While reading AI Powered Search (AIPS) by Trey Grainger et al., I learned about
the [_relatedness_ aggregation][solr-relatedness] in Solr. This is not directly
available in Elasticsearch, so how can we use it?

Basically it tells you, given a term, what other terms are often used together
with this term in your index.

According to AIPS, this is the backbone of Semantic Knowledge Graphs (SKGs) that
can be used for:

- Query Expansion
- Content based recommendations
- and more.

But if you're not using Solr, but Elasticsearch or OpenSearch, how can you use
the _relatedness_ fuction?

I won't go into the details of what it all means. You can read:

- [The Semantic Knowledge Graph: A compact, auto-generated model for real-time
  traversal and ranking of any relationship within a domain, 2016, Trey Grainger et al.][skg]
- [High-Quality Recommendation Systems with Elasticsearch][doug-recsys]

Here I'll just show you a beginning how we can achieve something similar with
the Elasticsearch `significant_terms` aggregation and the same scoring with a
painless scoring script.

## The `significant_terms` aggregation

Fortunately Elasticsearch has the [`significant_terms`][es-significant-terms] aggregation.

Let's see an example. We will use the same example as in the Solr documentation, so we can compare the results.

### Index some documents

```json
PUT hobbies

POST hobbies/_bulk
{"index": {"_id": "01"}}
{"id":"01","age":15,"state":"AZ","hobbies":["soccer","painting","cycling"]}
{"index": {"_id": "02"}}
{"id":"02","age":22,"state":"AZ","hobbies":["swimming","darts","cycling"]}
{"index": {"_id": "03"}}
{"id":"03","age":27,"state":"AZ","hobbies":["swimming","frisbee","painting"]}
{"index": {"_id": "04"}}
{"id":"04","age":33,"state":"AZ","hobbies":["darts"]}
{"index": {"_id": "05"}}
{"id":"05","age":42,"state":"AZ","hobbies":["swimming","golf","painting"]}
{"index": {"_id": "06"}}
{"id":"06","age":54,"state":"AZ","hobbies":["swimming","golf"]}
{"index": {"_id": "07"}}
{"id":"07","age":67,"state":"AZ","hobbies":["golf","painting"]}
{"index": {"_id": "08"}}
{"id":"08","age":71,"state":"AZ","hobbies":["painting"]}
{"index": {"_id": "09"}}
{"id":"09","age":14,"state":"CO","hobbies":["soccer","frisbee","skiing","swimming","skating"]}
{"index": {"_id": "10"}}
{"id":"10","age":23,"state":"CO","hobbies":["skiing","darts","cycling","swimming"]}
{"index": {"_id": "11"}}
{"id":"11","age":26,"state":"CO","hobbies":["skiing","golf"]}
{"index": {"_id": "12"}}
{"id":"12","age":35,"state":"CO","hobbies":["golf","frisbee","painting","skiing"]}
{"index": {"_id": "13"}}
{"id":"13","age":47,"state":"CO","hobbies":["skiing","darts","painting","skating"]}
{"index": {"_id": "14"}}
{"id":"14","age":51,"state":"CO","hobbies":["skiing","golf"]}
{"index": {"_id": "15"}}
{"id":"15","age":64,"state":"CO","hobbies":["skating","cycling"]}
{"index": {"_id": "16"}}
{"id":"16","age":73,"state":"CO","hobbies":["painting"]}
```

### Query

Using the default `significant_terms` aggregation.

```json
POST hobbies/_search
{
  "query": {
    "term": {
      "hobbies.keyword": "cycling"
    }
  },
  "size": 0,
  "aggs": {
    "r1": {
      "significant_terms": {
        "field": "hobbies.keyword",
        "min_doc_count": 1
      }
    }
  }
}
```

We get this as a result:

```json
  "aggregations": {
    "r1": {
      "doc_count": 4,
      "bg_count": 16,
      "buckets": [
        {
          "key": "cycling",
          "doc_count": 4,
          "score": 3,
          "bg_count": 4
        },
        {
          "key": "darts",
          "doc_count": 2,
          "score": 0.5,
          "bg_count": 4
        },
        {
          "key": "soccer",
          "doc_count": 1,
          "score": 0.25,
          "bg_count": 2
        },
        {
          "key": "swimming",
          "doc_count": 2,
          "score": 0.16666666666666666,
          "bg_count": 6
        },
        {
          "key": "skating",
          "doc_count": 1,
          "score": 0.08333333333333333,
          "bg_count": 3
        }
      ]
    }
```

So what does this mean?

- `r1.doc_count`: there are 4 documents that match _cycling_
- `r1.bg_count`: there are 16 documents in total
- for `soccer`
  - `doc_count`: 1 document has _cycling_ AND _soccer_
  - `bg_count`: 2 documents have _soccer_
  - `score`: by default the JLH Score is used.

## Solr relatedness calculation

The scores are different from the Solr documentation example. But we see
Elasticsearch also supports other scoring functions. After trying them none of
those get the same scores. Also the Solr scores are always nicely between -1 and 1.

So how does Solr calculated the scores?

Looking at the [source code][gh-solr-relatedness], we see:

```java
  /**
   * This is an aproximated Z-Score, as described in the "Scoring Semantic Relationships" section of
   * "<a href="https://arxiv.org/pdf/1609.00464.pdf">The Semantic Knowledge Graph: A compact,
   * auto-generated model for real-time traversal and ranking of any relationship within a
   * domain</a>"
   *
   * <p>See Also:
   *
   * <ul>
   *   <li><a href="https://s.apache.org/Mfu2">java-user@lucene Message-ID:
   *       449AEB60.4070300@alias-i.com</a>
   *   <li><a
   *       href="https://lingpipe-blog.com/2006/03/29/interesting-phrase-extraction-binomial-hypothesis-testing-vs-coding-loss/">Phrase
   *       Extraction: Binomial Hypothesis Testing vs. Coding Loss</a>
   * </ul>
   */
  // NOTE: javadoc linter freaks out if we try doing those links as '@see <a href=...' tags
  public static double computeRelatedness(
      final long fg_count, final long fg_size, final long bg_count, final long bg_size) {
    final double fg_size_d = (double) fg_size;
    final double bg_size_d = (double) bg_size;
    final double bg_prob = (bg_count / bg_size_d);
    final double num = fg_count - fg_size_d * bg_prob;
    double denom = Math.sqrt(fg_size_d * bg_prob * (1 - bg_prob));
    denom = (denom == 0) ? 1e-10 : denom;
    final double z = num / denom;
    final double result =
        0.2 * sigmoidHelper(z, -80, 50)
            + 0.2 * sigmoidHelper(z, -30, 30)
            + 0.2 * sigmoidHelper(z, 0, 30)
            + 0.2 * sigmoidHelper(z, 30, 30)
            + 0.2 * sigmoidHelper(z, 80, 50);
    return roundTo5Digits(result);
  }
```

The same _foreground_ and _background_ counts are used that are available in
Elasticsearch. So maybe we can create a script score?

## Relatedness Script Score

After a few tries, we can port the Solr java code to Elasticsearch Painless
script:

```json
PUT _scripts/relatedness
{
  "script": {
    "lang": "painless",
    "source": """
        double fgCount = 1.0*params._subset_freq;
        double fgTotal = 1.0*params._subset_size;
        double bgCount = 1.0*params._superset_freq;
        double bgTotal = 1.0*params._superset_size;

        if (fgTotal == 0 || bgTotal == 0) return 0;

        // Compute background probability
        double bgProb = bgCount / bgTotal;

        // Compute expected count in foreground
        double expected = fgTotal * bgProb;

        // Z-score
        double num = fgCount - expected;
        double denom = Math.sqrt(expected * (1.0 - bgProb));
        denom = (denom == 0) ? 1e-10 : denom;
        double z = num / denom;

        // Inlined sigmoid functions
        double s1 = (z + (-80)) / (50 + Math.abs(z + (-80)));
        double s2 = (z + (-30)) / (30 + Math.abs(z + (-30)));
        double s3 = (z + 0) / (30 + Math.abs(z + 0));
        double s4 = (z + 30) / (30 + Math.abs(z + 30));
        double s5 = (z + 80) / (50 + Math.abs(z + 80));

        double result = 0.2 * s1 + 0.2 * s2 + 0.2 * s3 + 0.2 * s4 + 0.2 * s5;

        return Math.round(result * 1e5) / 1e5;
      """
  }
}
```

And try it in the `significant_terms` aggregation:

```json
POST hobbies/_search
{
  "query": {
    "term": {
      "hobbies.keyword": "cycling"
    }
  },
  "size": 0,
  "aggs": {
    "r1": {
      "significant_terms": {
        "field": "hobbies.keyword",
        "min_doc_count": 1,
        "script_heuristic": {
          "script": {"id": "relatedness"}
        }
      }
    }
  }
}
```

And when using the same query as the Solr documentation:

```json
POST hobbies/_search
{
  "query": {"match_all": {}},
  "size": 0,
  "aggs": {
    "hobby": {
      "filter": {
        "range": {"age": {"gte": 35}}
      },
      "aggs": {
        "r1": {
          "significant_terms": {
            "field": "hobbies.keyword",
            "script_heuristic": {
              "script": {"id": "relatedness"}
            }
          }
        }
      }
    }
  }
}
```

We get the same scores!

```json
  ...
  "aggregations": {
    "hobby": {
      "doc_count": 9,
      "r1": {
        "doc_count": 9,
        "bg_count": 16,
        "buckets": [
          {
            "key": "golf",
            "doc_count": 5,
            "score": 0.01225,
            "bg_count": 6
          },
          {
            "key": "painting",
            "doc_count": 6,
            "score": 0.01097,
            "bg_count": 8
          }
        ]
      }
    }
  }
  ...
```

## Conclusion

With a painless script we are able to get the same scores as the Solr aggregation.

Should you use a script score? I'm not so sure. Probably we're often more
interested in the relative scores than the absolute numbers. The built in score
functions will work fine for that, and give you options to tweak if you want
more common terms or more unique terms.

But the benefit is that we can follow along with examples and documentation that
uses Solr, and try it out with Elasticsearch or OpenSearch, and verify we get
the same results.

[skg]: https://arxiv.org/abs/1609.00464
[doug-recsys]: https://opensourceconnections.com/blog/2016/09/09/better-recsys-elasticsearch/
[solr-relatedness]: https://solr.apache.org/guide/solr/latest/query-guide/json-facet-api.html#relatedness-and-semantic-knowledge-graphs
[es-significant-terms]: https://www.elastic.co/docs/reference/aggregations/search-aggregations-bucket-significantterms-aggregation
[gh-solr-relatedness]: https://github.com/apache/solr/blob/releases/solr/9.8.1/solr/core/src/java/org/apache/solr/search/facet/RelatednessAgg.java#L758-L773
