---
title: Rank with weighted document expansions in Elasticsearch
---

Say you have a document. You can assign tags to them, so it makes it beter
discoverable. But if a document has a tag, it has the tag or it does not have
the tag.

Techniques like SPLADE, doc2query, or Semantic Knowledge Graphs (SKG) can take
your document and find related terms for them. But not all related terms are
equal, some are more related than others.

If search keyword matches on the stronly related keyword, you want it to weight
more than if it matches on a weakly related keyword.

This is possible with Elasticsearch, using nested fields and a `function_score`
query.

## Mapping

First we have to setup a mapping. Use the
[`nested`](https://www.elastic.co/docs/reference/elasticsearch/mapping-reference/nested)
field type.

As nested properties we have a `name` and `weight` fields.

Using a structure like `{"keyword1": 2.0, "keyword 2": 1.0}` would lead to
[mapping
explosion](https://www.elastic.co/docs/troubleshoot/elasticsearch/mapping-explosion).

```json
PUT nested_weights_test
{
  "mappings": {
    "properties": {
      "title": {"type": "text"},
      "tags": {
        "type": "nested",
        "properties": {
          "name": { "type": "text" },
          "weight": { "type": "float" }
        }
      }
    }
  }
}
```

## Query

Add some documents.

```json
PUT nested_weights_test/_doc/1
{
  "title": "foo bar",
  "tags": [
    {"name": "abc", "weight": 2.0},
    {"name": "xyz", "weight": 1.0}
  ]
}
PUT nested_weights_test/_doc/2
{
  "title": "colors",
  "tags": [
    {"name": "blue", "weight": 1.5},
    {"name": "red", "weight": 0.5}
  ]
}
```

And run the query:

```json
POST nested_weights_test/_search
{
  "query": {
    "bool": {
      "should": [
        {
          "nested": {
            "path": "tags",
            "query": {
              "function_score": {
                "query": {
                  "match": {
                    "tags.name": "xyz"
                  }
                },
                "field_value_factor": {
                  "field": "tags.weight"
                }
              }
            }
          }
        },
        {
          "nested": {
            "path": "tags",
            "query": {
              "function_score": {
                "query": {
                  "match": {
                    "tags.name": "blue"
                  }
                },
                "field_value_factor": {
                  "field": "tags.weight"
                }
              }
            }
          }
        }
      ]
    }
  },
  "explain": true
}
```

## Reponse

This is what the above query responds:

```json
{
  "hits": {
    // ...
    "max_score": 1.8059592,
    "hits": [
      {
        "_index": "nested_weights_test",
        "_id": "2",
        "_score": 1.8059592,
        "_source": {
          "title": "colors",
          "tags": [
            {
              "name": "blue",
              "weight": 1.5
            },
            {
              "name": "red",
              "weight": 0.5
            }
          ]
        }
      },
      {
        "_index": "nested_weights_test",
        "_id": "1",
        "_score": 1.2039728,
        "_source": {
          "title": "a",
          "tags": [
            {
              "name": "abc",
              "weight": 2
            },
            {
              "name": "xyz",
              "weight": 1
            }
          ]
        }
      }
    ]
  }
}
```

Both the BM25 scores (the `match`) scores are 1.2, but the match on `blue` is
multiplied by 1.5, so resulting in a final score of 1.8. The match on `xyz` is
multiplied by 1, so stays 1.2.

## Conclusion

With this technique with the nested field and query in combination with the
`function_score` query we can weight the related keyword. e.g. keywords from a model
like SPLADE, semantic knowledge graphs, or something else that also has a
_relatedness_ weight.
