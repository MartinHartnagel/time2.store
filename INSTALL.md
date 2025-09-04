# Installation

- upload to host with a valid https certificate
- browse to /check_pdo.php to check for available PDO support of your host, copy the full URL
- configure in common.php the database to use (sqlite is initally activated)
- set DB_ALLOW_CREATE_TABLES to true to create tables
- open connections in https://time2.emphasize.de and add a storage connection, replace "host/path" with the full URL from above
- wait 10 seconds
- set DB_ALLOW_CREATE_TABLES to false again to disallow table creation
- remove check_pdo.php 
- remove this INSTALL.md