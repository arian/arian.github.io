---
title: "Reactive ReactJS: improving data flow using reactive streams"
date: 2015-02-16 13:31:20
tags: [reactjs, rx, baconjs, flux]
comments: yes
---

Many people that use ReactJS as their renderer, are using some kind of the
[Flux][] architecture to store data, react to actions and notify components
about changes. After a University project involving Scala and RxJava, I wanted
to use these ideas together with ReactJS views. Besides that I found two things
missing in the Flux architecture:

1. composing different kinds of data easily
2. interaction with the server

Of course there are ways to solve this, but perhaps reactive streams can help
easing these shortcomings.

<!-- more -->

## Reactive Streams

The mathematical definition of Functional Reactive Programming (FPR), defines a
value *over time*, [so past, present and future][FRP]. In reality we have to
make a few simplifications and changes to make it work in practice, so lets
just call it *reactive streams*.

In JavaScript the two most well known implementations of this paradigm are
[RxJS][] and [BaconJS][]. There are a few differences, but both work fine.

The basic idea is like an Event Emitter but then a lot better! Let me give you
an example of a simple Event Emitter:

```js
const emitter = new EventEmitter();

emitter.on('value', x => console.log(x));

emitter.emit('value' 1);
emitter.emit('value' 2);

// logged 1, 2
```

The biggest problem with this is, is that you can't modify or compose the
values between the `emit` and the `subscriber`. With a reactive stream, you can
do the following instead:

```js
const bus = new Bacon.Bus();

bus
  .map(x => x * 2)
  .filter(x => x >= 0)
  .skipDuplicates();

bus.subscribe(x => console.log(x));

bus.push(1);
bus.push(-2);
bus.push(2);

// logged: 2, 4
```

### Combining Streams

As you can see, it is really easy to manipulate and filter values in the
stream. But that's not all! The streams are also easily composable. You can
combine, merge or zip streams, among others:

```js
// two streams, that will emit the values in the arrays separately
const a = Bacon.fromArray([1,2,3]);
const b = Bacon.fromArray([4,5,6,7]);

// zip the nth item from each stream
a.zip(b, (x, y) => [x, y]).log()
// logs: [1,4] [2,5] [3,6]

// combine the latest values from each stream
a.combine(b, (x, y) => [x, y]).log()
// logs: [1,4] [2,5] [3,6] [3,7]

// emit all values from either stream
a.merge(b).log()
// logs: 1 2 3 4 5 6 7
```

Another very cool and useful feature is `scan`. This operation is like a `fold`
or `reduce` on a list, but then over time.

```js
Bacon.fromEventTarget(document.body, 'click')
  .scan(0, (acc, event) => acc + 1)
  .log();
```

This logs the total number of clicks each time the user clicks the document.
The `scan` functionality, together with *join patterns*, is a very powerful
method to keep the current state of something.

## Using Reactive Streams for Application State

Instead of a Flux Store, the data source can be a stream. Basically the React
Component doesn't listen to a Flux event emitter, it subscribes to the stream.

This could look like:

```js
// Create a dummy "time" stream
const time = Bacon.fromBinder(observer => {
  const timer = setTimeout(function() {
    observer(Date.now());
  }, 1000);
  return function() {
    clearTimeout(timer);
  };
});

// The view, that subscribes to the stream
const Timer = React.createClass({
  getInitialState: function() {
    return {time: 0};
  },
  componentDidMount: function() {
    // onValue or subscribe return a function which will unsubscribe from the stream
    this._unsubscribe = time.onValue(time => this.setState({time: time}));
  },
  componentWillUnmount: function() {
    this._unsubscribe();
  },
  render: function() {
    return (
      <div>Current Time: {this.state.time}</div>
    );
  }
});
```

The `componentDidMount` and the `componentWillUnmount` can potentially be
abstracted away in a mixin. Then what's left is the stream, an observable, that
emits a new value each second. On the receiving end of the stream, the state of
component is set, which triggers a re-render.

### Todo Application

Of course a simple timer isn't very interesting, so let's make a Todo App.
One important thing that is required is that it has to synchronize with the
server. The server is using some REST-like API, imagine something like this:

- `GET` `/list` responds with a list of todos
- `PUT` `/list/item` adds a new todo, responds with the saved item
- `POST` `/list/item/{id}` updates an item, responds with updated item.
- `DELETE` `/list/item/{id}` removes an item

Now to build our application state streams, we'll create a stream that fetches
the initial data from the server. Besides that there, are three streams coming
from the GUI that are user actions. If the user wants to add, update or remove
an item, an action is pushed to the corresponding stream. These actions need to
fire a server request. After such a request the list, that is rendered, needs
to be updated as well.

Creating the first stream to fetch all items from the server is pretty
straight-forward:

```js
const initialList = Bacon.fromPromise(fetch('/list'));
```

If we use this stream to render the view, we will see the list of todos.

```js
const {div} = React.DOM;
const Item = React.createFactory(require('./Item'));

const List = React.createClass({
  getInitialState: function() {
    return {items: []};
  },
  componentDidMount: function() {
    this._unsubscribe = initialList.onValue(items => this.setState({items: items}));
  },
  componentWillUnmount: function() {
    this._unsubscribe();
  },
  render: function() {
    const items = this.state.items.map(item => Item({item: item, key: item.id}));
    return div(null, items);
  }
});
```

This renders the list of items that was received from the server, however we
cannot update it in any way yet, unless we do a full page refresh.

Somehow we need to handle the user actions, and process them in some way. To do
this, we have to use a *bus* (in Rx it's a *subject*):

```js
const removeItemClicked = new Bacon.Bus();

// somewhere else in your components
const {div, button} = React.DOM;
const Item = React.createClass({
  onRemove: function() {
    removeItemClicked.push(this.props.item.id);
  },
  render: function() {
    return div(null,
      this.props.item.title,
      button({onClick: this.onRemove}, 'remove')
    );
  }
});
```

Now we can subscribe to the `removeItemClicked` stream, which will emit each
time the user clicks the remove button of an item. But that won't change
anything yet!

To actually do this, we can use the `flatMap(f)` method. This will execute
function `f` each time with a value. The function `f` can return another
stream, so the original `flatMap` returns a stream that now emits values coming
form the inside stream. To illustrate this, lets look at an example with just
arrays:

```js
Bacon.fromArray([10, 20])
  .flatMap(function(x) {
    return Bacon.fromArray([1, 2, 3].map(y => x + y));
  })
  .log() // logs 11, 12, 13, 21, 22, 23
```

It takes the first item, and then emits each element of the inner list, then
takes the second element and maps and flattens that, etc.

In the flatMap, we can also return a stream that does a request to the server,
like this:

```js
const removedItemOnServer = removeItemClicked
  .flatMap(itemId =>
    Bacon.fromPromise(fetch('/list/' + itemId, {method: 'delete'}))
  )
  .map(response => response.status == 200 ? itemId : new Error(response.statusText))
  .log();
```

New we have a stream of IDs that are removed by the server.

We can do a similar thing for the adding and editing actions.

#### Combining the streams

In the text above we were rendering the list that was fetched from the server
by the initial request. Now we can also do the server actions, but we don't
update the view yet. To do this, we need to join the different streams. One
effective way to do this are the *join patterns*.

BaconJS has a useful feature to do this: `Bacon.when`. You start with an
initial *seed*. It then pattern matches on the different streams, and each time
one of the matched events has a new value, you combine the previous accumulator
with the value from the stream, which will be the output of the stream.

```js
const updatedTodoItems = Bacon.when(
  [], // initial empty list
  [initialList], (oldList, newList) => list, // we get the entire list
  [addedItemOnServer], (oldList, newItem) =>
    // add new item to the array
    oldList.concat(newItem),
  [editedItemOnServer], (oldList, updatedItem) =>
	// remove old item, add new item
    oldList.filter(item => item.id != updatedItem.id).concat(updatedItem),
  [removedItemOnServer], (oldList, itemId) =>
	// remove the item from the array
    oldList.filter(item => item.id != itemId)
);
```

Each time one of the streams emit, the old list is updated with the value on
the stream, depending which event it was, and eventually the new list is
emitted.

If we subscribe to this `updatedTodoItems` stream in the `List` component,
rather than the `initialList` stream, the To Do list will nicely match with the
values that are saved on the server!

Here we used a simple JavaScript array, but you could write your own classes
that contain a list of the items, or use [immutable-js], which would be a
really good idea!

Graphically the system would look like this:

![Streams overview](/assets/react-streams/overview.svg)

### Modifying Streams

Remember you could apply operations like `map` and `filter` on streams? What if
you want to do something extra in one view, and use the original data in the
other. Do you need to create an entire new store, have weird dependencies
between stores? With streams you can simply create a new stream by doing `map`.

```js
const sortedUpdatedItems = updatedTodoItems
  .map(items => items.sort((a, b) => a.title < b.title));
```

Or often you want to get the latest items from one stream, and combine it with
user data, using the `combine` it's easy as:

```js
const itemsWithUsers = updatedTodoItems
  .combine(users, (items, users) =>
    items.map(item => item.user = users[item.user_id]));
```

## Wrap Up

Using streams for your application data gives a very declarative way of
structuring the flows of data. By mapping over the streams and combining
streams we can create a structure where we can simply incorporate server
updates, accumulate the application state over time and update the views.

[Flux]: http://facebook.github.io/flux/
[FRP]: https://github.com/ReactiveX/RxJava/pull/1036#issuecomment-40454410
[RxJS]: https://github.com/Reactive-Extensions/RxJS/
[BaconJS]: http://baconjs.github.io/
[immutable-js]: http://facebook.github.io/immutable-js/
