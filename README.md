# Time2Emphasize store

Download to install a storage service on your hosted domain and connect it to the Time2Emphasize Webapp.

## Mimimum Requirements

Your hosted domain *must* have a valid Https certificate! 
Nginx, PHP and PDO-SQLite/-MySql should be available! 

To check this and determine the url for the connection, open the following in your browser: `https://your-domain/path-to/check_pdo.php`

A successful response will look like
```
Array
(
    [0] => mysql
    [1] => sqlite
)
```
showing that both sqlite and mysql PDO extensions are available.

One can then use `https://time2.emphasize.de?m=s&d=your-domain/path-to` as a connection. Default a sqlite db flatfile will be created with your automatically generated user-id as a customer identifier.

Notice: `check_pdo.php` can be deleted on your server thereafter.

## Using the Time2Emphasize REST API

After connecting your storage in the Time2Emphasize Webapp, the endpoints will be made accessable under https://time2.emphasize.de/api in the Time2Emphasize REST API for testing and integration of git-hooks or other implementations.
