
var watch = require("node-watch");
var exec = require("child_process").exec;
var async = require('async');
var fs = require('fs');

function build() {
  async.series([

    function(callback) {
      exec("./node_modules/.bin/hexo generate", {
        cwd: __dirname
      }, function(err) {
        if (err) return callback(err);
        console.log("generated blog");
        callback();
      });
    },

    function(callback) {
      var htmlFile = __dirname + "/public/index.html";
      var phpFile = __dirname + "/public/index.php";

      exec("cp -R _index/* public", {
        cwd: __dirname
      }, function(err) {
        if (err) return callback(err);

        console.log("done copying PHP");

        var html = fs.readFileSync(htmlFile, "utf-8");
        var php = fs.readFileSync(phpFile);

        php = html.replace('{{content}}', php);

        fs.writeFileSync(phpFile, php);
        fs.unlink(htmlFile);

        callback();

      });
    },

    function(callback) {
      exec("./node_modules/.bin/stylus themes/light/source/css/style.styl --include ./node_modules/nib/lib/ -o public/css/", {
        cwd: __dirname
      }, function(err) {
        if (err) return callback(err);
        console.log("generated css");
        callback();
      });
    }
  ], function(err){
    if (err) throw err;
    console.log('done');
  })
}

watch(__dirname + "/source", build);
watch(__dirname + "/themes", build);

build();
