
var exec = require('child_process').exec;
var fs = require('fs');

var htmlFile = __dirname + "/../public/index.html";
var phpFile = __dirname + "/../public/index.php";

hexo.on('generateAfter', function() {
  exec("cp -R ./_index/* ../public", {
    cwd: __dirname
  }, function(err) {
    if (err) throw err;
    console.log("done copying PHP");

    var html = fs.readFileSync(htmlFile, "utf-8");
    var php = fs.readFileSync(phpFile);

    php = html.replace('{{content}}', php);

    fs.writeFileSync(phpFile, php);
    fs.unlink(htmlFile);

  });
});
