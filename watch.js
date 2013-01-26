
var watch = require("node-watch");
var exec = require("child_process").exec;

function build() {
  exec("./node_modules/.bin/hexo generate", {
    cwd: __dirname
  }, function(err) {
    if (err) throw err;
	console.log("generated blog");
  });
}

watch(__dirname + "/source", build);
watch(__dirname + "/themes", build);

build();
