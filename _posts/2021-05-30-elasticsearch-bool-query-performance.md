---
title: ElasticSearch Boolean Query Performance
---

A Boolean Query caused performance issues. The problem was that an empty `filter` clause behaved differently from a non-empty `filter` clauses with `match_all` and `should` clauses, because of tricky `minimum_should_match` behavior. I'll try to explain what happened and how to fix this issue.

A few weeks ago I changed an ElasticSearch query in the application I was working on. The new query was structured in such a way it should return better search results, and that it would be easier to tweak which fields contributed to the scores of the documents. The changes were reviewed, tested, and merged, and it looked good.

Then we deployed these changes to production. It still looked good, and with the live data it did give better results given the search inputs.

Then, during the day, traffic increased. And it didn't look good anymore. Our monitoring systems notified that not all request could be handled anymore by the application and the Load Balancer was queued requests. The response times got really high! It turned out the load on ElasticSearch got too high and overloaded.

We quickly rolled back the change, and things stabilized. We moved the new version of the query under a feature flag to dynamically enable or disable it later. So far the stressful part, now, why was the performance so different?

### Compound Queries

For the search functionality of the application we want to search documents for a specific search term the user enters. Aside from just a name or description, each document also has fields like a ratings or views that should affect the scoring.

ElasticSearch provides the [Function Score Query](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-function-score-query.html) for this: an inner query that provides results with scores, of which the scores are adjusted by some function (e.g. multiplying with another field of the document). So the resulting query is a query with a query inside it. The documents are then filtered using the `min_score` field, and sorted by the new score.

### Boolean Query

Another type of compound query is the _Boolean Query_. This query combines different queries in four types, and each type is a list of queries.

- _filter_: either the document matches a query or not. It doesn't affect scoring.
- _should_: the query contributes a score, e.g. a query results into a score how much it matches a search term. If any subquery in the _should_ list matches the document is included.
- _must_: almost the same as _should_, but documents must match all subqueries.
- _must_not_: the query must match, but the scores are ignored.

An example of a query like this is:

```json
{
  "query": {
    "bool": {
      "filter": {
        "term": { "tags": "production" }
      },
      "should": [
        { "term": { "tags": "env1" } },
        { "term": { "tags": "deployed" } }
      ]
    }
  }
}
```

It reads like: filter all documents that have `production` in the `tags` field. Then documents with `env1` or `deployed` in the `tags` fields get a high score.

This is almost exactly what we had, except embedded in a Function Score query:

```json
{
  "size": 20,
  "query": {
    "function_score": {
      "query": {
        "bool": {
          "filter": {
            "term": { "tags": "production" }
          },
          "should": [
            { "term": { "tags": "env1" } },
            { "term": { "tags": "deployed" } }
          ]
        }
      },
      "min_score": 1
    }
  }
}
```

#### Filters

The code dynamically added filters to the bool query. However in the default cause, it didn't add filters, but returned a `match_all` query, assuming these cases would be identical

```json
{
  "bool": {
    "filter": [{ "match_all": {} }],
    "should": [{ "term": { "tags": "env1" } }]
  }
}
```

and

```json
{
  "bool": {
    "filter": [],
    "should": [{ "term": { "tags": "env1" } }]
  }
}
```

**But it is not!!**, the results are the same, but not how the query is executed.

The devil is in the details of the [documentation](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-bool-query.html#bool-min-should-match):

> If the bool query includes at least one should clause and no must or filter clauses, the default value is 1. Otherwise, the default value is 0.

So `0` or `1` item in the `filter` clause changes the behavior! We want that it matches the `should` clause always. Each non-matching document is eventually still filtered by the outer function query (`min_score`), but not by the bool query, if there is one item in the `filter` clause.

#### Use `"minimum_should_match": 1`

The quickest solution would be to add `"minimum_should_match": 1` to the query, as that would ensure each document is only included if it matches one item in the `should` clause:

```json
{
  "bool": {
    "filter": [{ "match_all": {} }],
    "should": [{ "term": { "tags": "env1" } }],
    "minimum_should_match": 1
  }
}
```

#### Solution: use the `must` clause

A better solution in our case is to use `must`. That ensures each document matches the subquery and it contributes to the score.

```json
{
  "bool": {
    "filter": [{ "match_all": {} }],
    "must": [{ "term": { "tags": "env1" } }]
  }
}
```

And even better, don't treat `match_all: {}` as 'identity', but leave the `filter` clause empty, and only add one if we really want to filter something (e.g. language, ...):

```json
{
  "bool": {
    "filter": [],
    "must": [{ "term": { "tags": "env1" } }]
  }
}
```

### Debugging

Finally, some things we did to debug this.

_Kibana_ is really useful as a UI to experiment with ElasticSearch queries and explore the data. Especially the "Dev Tools console".

You can execute a query, and the JSON ElasticSearch returns contains the `took` property, which is how long the query took. This is a rough number, but can give you an indication of the order. Before the query usually took ~100ms, and after less than ~10ms!

But also the [_Search Profiler_](https://www.elastic.co/guide/en/kibana/current/xpack-profiler.html) is a useful tool. This gives insights into which part of the (compound) query takes the most time. In this case a lot of time was spent in `next_doc`, which makes sense when the bool query didn't filter out the documents that scored `0`.
