---
title: Setting up a MySQL database
date: 2013-02-26 21:00:40
tags: [mysql, reference]
---

This post is just for future reference with a few MySQL commands I can't really
remember yet for creating a new database and mysql user. Probably now I posted
them here, I will remember them ;). So it might be interesting or less
interesting for you.

First, login as root with:

```bash
mysql -u root -p
```

If you forgot your root password, follow
[these steps](http://ubuntu.flowconsult.at/en/mysql-set-change-reset-root-password/):

```bash
sudo service mysql stop
sudo mysqld --skip-grant-tables &
mysql -u root mysql
mysql> UPDATE user SET Password=PASSWORD('YOURNEWPASSWORD') WHERE User='root';
mysql> FLUSH PRIVILEGES; exit;
```

When logged in, create a new database:

```sql
CREATE DATABASE databasename;
```

And create a user for it, if we want a separate user at least:

```sql
GRANT ALL ON databasename.* TO myuser@localhost IDENTIFIED BY 'mypassword';
```

And that's usually what you need to know.
