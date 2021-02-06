---
title: Creating efficient File Storage of Uploads for Web Applications
---

At my company, [Symbaloo](https://symbaloo.com), we have various features that need storage of files, mainly images uploaded by users. Files are stored on the filesystem of the servers. The files are usually referenced from the database to a path on the file system. It's important to do this correctly, so we're sure the paths in the database point to existing files on the file system, and files on the file system have a reference in the database. It's bad if users see an `<img>` tag with a file that doesn't exist! On the other hand, to keep hosting costs down we don't want to store more than necessary. In this post I will explain how we solved this problem and now store files efficiently.

We'll solve the problem and discuss:

-   File naming using SHA-256 hashes
-   Storing references in a database
-   Garbage collecting files
-   Safely deleting files

### File naming scheme using SHA-256 hashes

A simple way to name files would be to create a database entry, which has an `id` field, and name the file `[id].png`, for example (`/some/path/1337.png`). A problem, however, is that we would need to know the `id` upfront, or rename the file after inserting the entry. Another problem is when we use CDN with caching, the cache would need to be purged or users will get out-of-date content. Using a [UUID](https://en.wikipedia.org/wiki/Universally_unique_identifier) would help, but we can do better!

We can look at the file content and generate a unique file name based on the file content: using a [SHA-256 hash](https://en.wikipedia.org/wiki/SHA-2). This is inspired by the way [git stores objects](https://git-scm.com/book/en/v2/Git-Internals-Git-Objects)

This has these two benefits:

-   The same files will get the same hash, so you won't store duplicate files. This is especially useful for cases that many database entries will refer to the same file.
-   File paths are based on the content, so it's perfect for generating URLs to these files in combination with CDNs.

#### Hashing A File

Let's review what it means to hash a file. A hash is some output based on the input of the hash function. Running the function with the same input always returns the same hash. A hash is also a one-way function, so from the hash it's impossible to get the original input.

SHA-256 hashes are always 32 bytes long (256 bits are 32 bytes of 8 bits 🤯). It doesn't matter how long the input is. Other hash functions might have different lengths. For example hashes from the SHA-1 hash function, that `git` uses, are 20 bytes long.

On the command line, using the `sha256sum` command you can test it:

```bash
$ echo -n "hello" | sha256sum
2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824  -
```

The result here is the _hex_ representation of 32 bytes. The returned string is 64 characters, so each two characters represents one byte in the hexadecimal notation: from `0` as `00` up to `255` as `ff`.

In Java or Kotlin (JVM) a `ByteArray` can be hashed using `MessageDigest`:

```kotlin
fun ByteArray.sha256(): ByteArray =
    MessageDigest.getInstance("SHA-256").run {
        update(this@sha256)
        digest()
    }

fun ByteArray.toHexString() =
    joinToString("") { "%02x".format(it) }

fun main() {
    println("hello".toByteArray().sha256().toHexString())
    // prints "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
}
```

To hash complete files it's good to use `DigestOutputStream` instead, to the hash while uploading/downloading/moving/editing the file and not load the entire file into memory:

```kotlin
fun main() {
    val inputStream = "hello".toByteArray().inputStream()
    val outputStream = ByteArrayOutputStream()
    val digestStream = DigestOutputStream(outputStream, MessageDigest.getInstance("SHA-256"))
    inputStream.copyTo(digestStream)
    println(digestStream.messageDigest.digest().toHexString())
    // prints "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
}
```

#### Hash to file name

Just 32 bytes isn't a filename yet. The hex representation of the bytes is a good string representation of the hash. When browsing the file system it can be slow if thousands of files are in the same directory, and some (older) file systems even have a limit how many files can be into a single directory. We can introduce some sub directories by taking the first bytes of the 32 byte hash and use that as directory name. Using two levels deep, a file containing the string `hello` would be saved to the file:

```
2c/f2/4dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
```

To format the hashes and parse a file path to the 32 byte hash we can use these functions:

```kotlin
fun toFileSystemPath(bytes: ByteArray, prefix: String, suffix: String): String {
    require(bytes.size == 32) { "bytes size must be 32 bytes" }
    val hash = bytes.toHexString()
    val path = "/$prefix/${hash.substring(0..1)}/${hash.substring(2..3)}"
    val filename = "${hash.drop(4)}$suffix"
    return "$path/$filename"
}

fun parseFileSystemPath(path: String, prefix: String, suffix: String): ByteArray? {
    val p = Pattern.quote(prefix)
    val s = Pattern.quote(suffix)
    val regex = Regex("^/$p/([0-9a-f]{2})/([0-9a-f]{2})/([0-9a-f]{60})$s$")
    val (a, b, c) = regex.matchEntire(path)?.destructured ?: return null
    return "$a$b$c".hexStringToByteArray()
}

fun main() {
    val path = toFileSystemPath("hello".sha256AsBytes(), prefix = "image", ".png")
    println(path)
    // prints "/image/2c/f2/4dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824.png"
    val hash = parseFileSystemPath(path, "image", ".png")?.toHexString()
    println(hash)
    // prints "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
}
```

### Storing File Reference in the database

If we let users upload all kinds of files, and not keeping track of them tightly, we will end up with many files on the file system that might or might not be used. To keep track of this we store the file names in a database table. If we want to be able to check the database if a specific file is actually used or not we need to be able to do a database query with the file name in the `WHERE` clause. If the database table contains many entries this would be really slow without an index. So let's see how that looks:

```sql
CREATE TABLE `tablename`(
  -- other columns
  `file` BINARY(32) NOT NULL,
  `fileId` BINARY(4) NOT NULL,
  KEY `fileId` (fileId)
);
```

As the file name is a 32 byte hash, we can use the `BINARY(32)` data type. The table has a second column with only 4 bytes. This as an index. A second column is a slight storage overhead, but smaller than using the complete 32 bytes as index for the fast table lookup.

With the index we can quickly check if the file has a reference in the database.

```sql
SELECT file FROM tablename di WHERE di.fileId = :fileId AND di.file = :file
```

With

```kotlin
val fileId = file.copyOfRange(0, 4)
```

The `fileId` index is not unique, so that returns multiple entries. With 4 bytes we have 256^4 is more than 4\*10^9 entries. So if the amount of entries in your database is less or in that order it won't be a problem at all.

### Garbage collecting files

In reality, it's super hard to keep the files on the file system exactly in-sync with the entries in the database: entries could be deleted by something in your application that doesn't check the files, the entries might be deleted directly, manually, from the database, or maybe deleted by some database cleanup batch processing.

Fortunately with the choice of the database scheme, the file naming, and nested folders, it becomes pretty straightforward to check a given file against the database and delete if it's obsolete.

Modern programming languages often use Garbage Collection. That means you as a programmer don't need to cleanup memory manually, but the runtime checks objects and frees them from memory if they're not used. For our files we can do something similar. Traverse the file system in the background at given intervals and check if files are referenced.

Our garbage collection background job does the following steps at given intervals.

-   pick a random nested folder as the file paths are two levels deep.
-   check all files in a given folder
-   parse the file paths into the 32 byte hash using the `parseFileSystemPath` function
-   query the database and return the used entries
-   delete the files of which the hashes are not in the database.

### Safely deleting files

There is one tricky moment while files are created or deleted at the same time: a web request just stores a file using the hashed file name on the file system. At this moment, the file isn't stored in the database just yet. At the same time, the garbage collector or another requests decided the file can be removed, checks the database and doesn't find a reference, so thinks it is okay to delete the file. This is not good because the just created file got deleted, while the original request could store the reference into the database anyway, leaving us in a bad state. The newly created file must be protected from other processes.

A `.lock` file can protect the new created file from deletion until a reference is saved in the database:

-   The `OutputStream` the `DigestOutputStream` writes to, is writing to a temporary file (e.g. using `UUID().toString()`)
-   After that's done we know the hash.
-   Before moving the temporary file to the final location, we first create an empty file `[hash].lock`. If the lockfile already exists, we add a counter to the filename to create a unique lock for the process that creates the file.
-   We move the temporary file to the final location.
-   We store the file name / hash in the database
-   The `[hash].lock` is deleted

And when deleting a file:

-   We first check if there is a `[hash].lock` file
-   If that file exists, we can assume another process is creating the file, and don't need to delete it.

### Conclusion

Using file hashes together with a database index we can efficiently store files. The file system will keep clean as we can safely check if the file is referenced in the database, and garbage collecting by checking the files in the database if something slipped through.

Did you ever built something similar, or are there existing packages or frameworks you know of? Let me know in the comments!