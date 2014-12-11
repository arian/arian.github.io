title: ReactJS in Java Hello World
date: 2014-12-09 13:31:20
tags: [reactjs, java, javascript, server]
comments: yes
---


[ReactJS](http://facebook.github.io/react/) is becoming a hugely popular
solution for building complex Web Applications. It is so nice to use because
it really resembles building applications like you used to in the good old PHP
days a long time ago, where you construct the HTML once on the server, and be
done with it. Don't think about changing state over time, just render once, and
refresh once the data changed.

ReactJS runs entirely in the Browser. You give the components data, and it
constructs the DOM for you. This means that your initial page can be as simple
as:

```html
<!DOCTYPE>
<html>
<head><title>ReactJS Page</title></head>
<body><div id="app"></div></body>
<script>
React.render(MyComponent(), document.getElementById("app"));
</script>
<html>
```

Considering the first principle of Guillermo Rauch's *[7 Principles of Rich Web
Applictions][7 principles]*:

> "Server rendered pages are not optional"

And the tl;DR

> "Server rendering is not about SEO, it's about performance. Consider
> the additional roundtrips to get scripts, styles, and subsequent API
> requests. In the future, considering HTTP 2.0 PUSH of resources."

So we need to pre-render the DOM on the server too.

Fortunately this is really easy with ReactJS, using the `React.renderToString`
function. It's exactly like the `React.render` function, except rendering it to
in a DOM node, it returns a string. The HTML in that string contains
`data-react-*` attributes, so if you `React.render` on the client-side again,
it picks up the DOM that is already there and only applies the actual changes.
It takes the string as initial DOM state.

The thing with ReactJS, however, or with any client-side rendering engine, is
that it's written in JavaScript. That makes sense because usually it's used on
the client side. With NodeJS we have this amazing server-side runtime that can
run JavaScript on the server. So the usual option would be to use NodeJS to run
ReactJS to generate the server-side HTML.

The downside of NodeJS is that not every server configuration has NodeJS easily
available. For example a lot of servers use Ruby, Python, Java or PHP.

There are two options: run NodeJS as a "render service" or use an embedded
JavaScript runtime which is possible with Java.

### NodeJS as local Render Service

One option would be to run a NodeJS process on your server, and use an internal
port or Unix Socket to render the ReactJS components with NodeJS and use the
result in the original stack.

```js
var http = require('http');
var parse = require('url').parse;
var React = require('react');

var MyComponent = React.createClass({
	render: function() {
		return React.DOM.div(null, this.props.text);
	}
});

var server = http.createServer(function(req, res) {
	var text = parse(req.url, true).query.text || 'hello world';
	var html = React.renderToString(React.createFactory(MyComponent)({text: text}));
	res.end(html);
});

server.listen(3000);
```

And then your original code, for example your Python 3 server, can use this
very simple snippet to render the ReactJS component with NodeJS as service:

```python
import http.client
conn = http.client.HTTPConnection("localhost:3000")
conn.request("GET", "/?text=bar")
r1 = conn.getresponse()
print(r1.status, r1.reason)
print(r1.read())
```

The downside of this approach is that you have to send your data from your
program through a TCP port or a Unix Socket.

### ReactJS with the Embedded JS Runtime in Java

Java has an embedded JavaScript runtime already for a long time. First there
was Rhino, and now, since Java 8, there is Nashorn. In Java you have an entire
JavaScript runtime at your disposal. The simple Nashorn Hello World looks
something like this, directly taken from the [Nashorn website][] at Oracle:

```java
package sample1;

import javax.script.ScriptEngine;
import javax.script.ScriptEngineManager;

public class Hello {

  public static void main(String... args) throws Throwable {
    ScriptEngineManager engineManager = new ScriptEngineManager();
    ScriptEngine engine = engineManager.getEngineByName("nashorn");
    engine.eval("function sum(a, b) { return a + b; }");
    System.out.println(engine.eval("sum(1, 2);"));
  }
}
```

As it turns out is is relatively easy to run ReactJS in Java, as shown here:

```java
import javax.script.ScriptEngine;
import javax.script.ScriptEngineManager;

import java.io.FileReader;

public class Test {

	private ScriptEngine se;

	// Constructor, sets up React and the Component
	public Test() throws Throwable {
		ScriptEngineManager sem = new ScriptEngineManager();
		se = sem.getEngineByName("nashorn");
		// React depends on the "global" variable
		se.eval("var global = this");

		// eval react.js
		se.eval(new FileReader("node_modules/react/dist/react.js"));

		// This would also be an external JS file
		String component =
			"var MyComponent = React.createClass({" +
			"	render: function() {" +
			"		return React.DOM.div(null, this.props.text)" +
			"	}" +
			"});";

		se.eval(component);
	}

	// Render the component, which can be called multiple times
	public void render(String text) throws Throwable {
		String render =
			"React.renderToString(React.createFactory(MyComponent)({" +
			// using JSONObject here would be cleaner obviosuly
			"	text: '" + text + "'" +
			"}))";
		System.out.println(se.eval(render));
	}

	public static void main(String... args) throws Throwable {
		Test test = new Test();
		test.render("hello");
		test.render("hello world");
	}
}
```

As shown above, there are two approaches that make loading the initial page
load, with pre-rendered ReactJS components on the Server super fast, as the
browser has to do less, and you don't need an initial Ajax request for your
initial data. The NodeJS solution is very flexible, as it can be used for any
programming language, but it requires running a NodeJS process along your
normal server. If you're using Java, or anything that runs on the JVM (Java,
Scala, Clojure, JRuby, etc.) you can use the embedded version. Finally thanks
to ReactJS, it perfectly consumes your server-generated DOM structure, so after
updates, it just updates the difference, which makes it a very fluid and easy
to program solution!

[7 principles]: http://rauchg.com/2014/7-principles-of-rich-web-applications/
[Nashorn website]: http://www.oracle.com/technetwork/articles/java/jf14-nashorn-2126515.html
