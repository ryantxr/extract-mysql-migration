# extract-phinx
Extract a MySQL database to phinx

This script takes an existing database and produces a Phinx migration.
The migration it produces probably won't be perfect. You will likely 
have to review and tweak the file.

```
    php -f  extract_phinx_migration.php database user password host port > migration.php
```

