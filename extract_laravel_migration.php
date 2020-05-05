<?php
/**
 * Simple command line tool for creating laravel migration code from an existing MySQL database.
 *
 * Commandline usage:
 * ```
 * $ php extact_laravel_migration.php [database] [user] [password] > migration.php
 * ```
 */
if ($argc < 4) {
    echo '===============================' . PHP_EOL;
    echo 'Laravel MySQL migration generator' . PHP_EOL;
    echo '===============================' . PHP_EOL;
    echo 'Usage:' . PHP_EOL;
    echo 'php ' . $argv[0] . ' [database] [user] [password] [host] [port] > migration.php';
    echo PHP_EOL;
    exit;
}
$config = array(
    'name'    => $argv[1],
    'user'    => $argv[2],
    'pass'    => $argv[3],
    'host'    => $argc >= 5 ? $argv[4] : 'localhost',
    'port'    => $argc >= 6 ? $argv[5] : '3306'
);
function createMigration($mysqli, $indent = 2)
{
    $output = [];
    foreach (getTables($mysqli) as $table) {
        $fileOutput = getTableMigration($table, $mysqli, $indent);
        writeFile($table, $fileOutput);
        $output[] = $fileOutput;
    }
    return implode(PHP_EOL, $output) . PHP_EOL ;
}

function writeFile($table, $fileOutput)
{
    $info = makeNames($table);
    $content = '<' . '?php' . PHP_EOL.
        'use Illuminate\Support\Facades\Schema;' . PHP_EOL.
        'use Illuminate\Database\Schema\Blueprint;' . PHP_EOL.
        'use Illuminate\Database\Migrations\Migration;' . PHP_EOL.
        PHP_EOL.
    "class {$info['className']} extends Migration" . PHP_EOL.
    '{'. PHP_EOL.
        '    public function up()' . PHP_EOL.
        '    {' . PHP_EOL.
$fileOutput . PHP_EOL.
        '    }' . PHP_EOL.
        '    public function down()' . PHP_EOL.
        '    {' . PHP_EOL.
        "        Schema::dropIfExists('{$table}');" . PHP_EOL.
        '    }' . PHP_EOL.


    '}'. PHP_EOL;
    file_put_contents($info['filename'], $content);
}


function makeNames($table)
{
    $seq = strftime('%Y_%m_%d_%H%M%S');
    return [
        'filename' => sprintf('%s_create_%s_table.migration.php', $seq, $table),
        'className' => 'Create' . array_reduce(explode('_', $table), function($carry, $item){
            return $carry . ucfirst($item);
        }) . 'Table'
    ];
}

function tableIndexes($indexes)
{
    $indexesForTable = [];
    foreach($indexes as $in) {
        if ( ! array_key_exists($in['Key_name'], $indexesForTable) ) {
            // debuglog($in['Key_name'] . ' Non_unique ' . $in['Non_unique']);
            $indexesForTable[$in['Key_name']] = [
                'unique' => ($in['Non_unique'] != 1),
                'columns' => [$in['Column_name']]
            ];
        } else {
            $indexesForTable[$in['Key_name']]['columns'][] = $in['Column_name'];
        }
    }
    return $indexesForTable;
}

function getMysqliConnection($config)
{
    if ( isset($config['port']) ) {
        return new mysqli($config['host'], $config['user'], $config['pass'], $config['name'], $config['port']);
    }
    return new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
}
function getTables($mysqli)
{
    $res = $mysqli->query('SHOW TABLES');
    return array_map(function($a) { return $a[0]; }, $res->fetch_all());
}
function getTableMigration($table, $mysqli, $indent)
{
    $ind = getIndentation($indent);

    // Find the primary key
    $primaryKey = null;
    foreach (getColumns($table, $mysqli) as $column) {
        if ( isset($column['Key']) && $column['Key'] == 'PRI' ) {
            $primaryKey = $column['Field'];
        }
    }
    $output = array();
    $output[] = $ind . '// Migration for table ' . $table;
    
    $output[] = $ind . "Schema::create('{$table}', function (Blueprint \$table) {";
    $output[] = getIndentation($indent+1) . "\$table->increments('{$primaryKey}');";

    foreach (getColumns($table, $mysqli) as $column) {

        $output[] = getColumnMigration($column['Field'], $column, $indent + 1);

    }
    $tableIndexes = tableIndexes(getIndexes($table, $mysqli));
    // debuglog("indexes for table $table");
    // debuglog($tableIndexes);
    $output[] = '';
    $output[] = getIndentation($indent+1) . '// indexes';            
    foreach($tableIndexes as $indexName => $index) {
        if ( $indexName == 'PRIMARY' ) continue;
        $func = $index['unique'] ? 'unique' : 'index';
        if ( count($index['columns']) == 1 ) {
            $arg = "'{$index['columns'][0]}'";
        } elseif ( count($index['columns']) > 1 ) {
            $arg = '[';
            $first = true;
            foreach($index['columns'] as $c) {
                if ( $first ) {
                    $first = false;
                } else {
                    $arg .= ',';
                }
                $arg .= "'{$c}'";
            }
            $arg .= ']';
        }
        $output[] = getIndentation($indent+1) . "\$table->{$func}({$arg}, '{$indexName}');";

    }

    $output[] = $ind . "}\n";

    // if ( $primaryKey ) {
    //     $output[] = $ind . '$table = $this->table(\'' . $table . '\', [\'id\' => false, \'primary_key\' => \''.$primaryKey.'\']);';
    // }
    // else {
    //     $output[] = $ind . '$table = $this->table(\'' . $table . '\');';
    // }

    // $output[] = $ind . '$table';
    // foreach (getColumns($table, $mysqli) as $column) {

    //     $output[] = getColumnMigration($column['Field'], $column, $indent + 1);

    // }
    // if ($foreign_keys = getForeignKeysMigrations(getForeignKeys($table, $mysqli), $indent + 1)) {
    //     $output[] = $foreign_keys;
    // }
    // $output[] = $ind . '    ->create();';
    $output[] = PHP_EOL;
    return implode(PHP_EOL, $output);
}
function getColumnMigration($column, $columndata, $indent)
{
    $ind = getIndentation($indent);
    $type = getColumnType($columndata);
    $result = getColumnAttibutes($type, $columndata);
    $columnattributes = $result['string'];
    $attributes = $result['attributes'];
    // print_r($columndata); exit;
    if ( array_key_exists('identity', $attributes) && $attributes['identity'] == true ) {
        return;
    }
    debuglog("type $type");
    debuglog(print_r($attributes, true)); 
    $typeFunction = $type;
    $output = $ind . "\$table->{$typeFunction}('" . $column . '\'' .  ( $type == 'string' ? ', ' . $attributes['limit'] : null)  . ')';
    if ( array_key_exists('nullable', $attributes) && $attributes['nullable'] == true ) $output .= '->nullable()';
    if ( array_key_exists('default', $attributes) ) $output .= "->default({$attributes['default']})";
    if ( array_key_exists('signed', $attributes) && $attributes['signed'] == false ) $output .= '->unsigned()';

    $output .= ';';
    // $output .= '/* ' . print_r($attributes, true) . '*/';

    return $output;
}
function getIndexMigrations($indexes, $indent)
{
    debuglog('indexes: ' . print_r($indexes, true));
    $ind = getIndentation($indent);
    $keyedindexes = [];
    foreach($indexes as $index) {
        if ($index['Column_name'] === 'id') {
            continue;
        }
        $key = $index['Key_name'];
        if (!isset($keyedindexes[$key])) {
            $keyedindexes[$key] = array();
            $keyedindexes[$key]['columns'] = array();
            $keyedindexes[$key]['unique'] = $index['Non_unique'] !== '1';
        }
        $keyedindexes[$key]['columns'][] = $index['Column_name'];
    }
    $output = [];
    foreach ($keyedindexes as $index) {
        $columns = '[\'' . implode('\', \'', $index['columns']) . '\']';
        $options = $index['unique'] ? '[\'unique\' => true]' : '[]';
        $output[] = $ind . '$table->index(' . $columns . ', ' . $options . ')';
    }
    return $output;
    // return implode(PHP_EOL, $output);
}
function getForeignKeysMigrations($foreign_keys, $indent)
{
    $ind = getIndentation($indent);
    $output = [];
    foreach ($foreign_keys as $foreign_key) {
        $output[] = $ind . "->addForeignKey('" . $foreign_key['COLUMN_NAME'] . "', '" . $foreign_key['REFERENCED_TABLE_NAME'] . "', '" . $foreign_key['REFERENCED_COLUMN_NAME'] . "', array("
            . "'delete' => '" . str_replace(' ', '_', $foreign_key['DELETE_RULE']) . "',"
            . "'update' => '" . str_replace(' ', '_', $foreign_key['UPDATE_RULE']) . "'"
        . "))";
    }
    return implode(PHP_EOL, $output);
}
/* ---- */
function getMySQLColumnType($columndata)
{
    $type = $columndata['Type'];
    // Recognize tinyint(1) unsigned as boolean
    if ( $type == 'tinyint(1) unsigned' ) {
        return 'boolean';
    } 
    $pattern = '/^[a-z]+/';
    preg_match($pattern, $type, $match);
    return $match[0];
}
function getColumnType($columndata)
{
    $type = getMySQLColumnType($columndata);
    switch($type) {
        case 'boolean':
            return 'boolean';
        case 'tinyint':
            return 'tinyInteger';
        case 'smallint':
            return 'smallInteger';
        case 'int':
            return 'integer';
        case 'mediumint':
            return 'mediumInteger';
        case 'timestamp':
            return 'timestamp';
        case 'date':
            return 'date';
        case 'datetime':
            return 'datetime';
        case 'enum':
            return 'enum';
        case 'char':
            return 'char';
        case 'text':
        case 'tinytext':
            return 'text';
        case 'varchar':
            return 'string';
        case 'decimal':
            return 'decimal';
        default:
            return '[' . $type . ']';
    }
}
function getColumnAttibutes($type, $columndata)
{
    debuglog(print_r($columndata, true));
    $attributes = [];
    $stringAttributes = [];
    // var_dump($columndata);
    // has NULL
    if ($columndata['Null'] === 'YES') {
        $stringAttributes[] = '\'null\' => true';
        $attributes['nullable'] = true;
    }
    // default value
    if ($columndata['Default'] !== null) {
        $default = preg_match('/^\d+$/', $columndata['Default']) ? $columndata['Default'] : '\'' . $columndata['Default'] . '\'';
        $stringAttributes[] = '\'default\' => ' . $default;
        $attributes['default'] = $default;
    } else {
        $attributes['default'] = 'null';
    }
    // on update CURRENT_TIMESTAMP
    if ($columndata['Extra'] === 'on update CURRENT_TIMESTAMP') {
        $stringAttributes[] = '\'update\' => \'CURRENT_TIMESTAMP\'';
        $attributes['extra'] = 'update CURRENT_TIMESTAMP';
    }
    // limit / length
    $limit = 0;
    switch (getMySQLColumnType($columndata)) {
        case 'boolean':
            //tinyint(1) unsigned
            break;
        case 'tinyint':
            // $limit = 'MysqlAdapter::INT_TINY';
            break;
        case 'smallint':
            // $limit = 'MysqlAdapter::INT_SMALL';
            break;
        case 'mediumint':
            // $limit = 'MysqlAdapter::INT_MEDIUM';
            break;
        case 'bigint':
            // $limit = 'MysqlAdapter::INT_BIG';
            break;
        case 'tinytext':
            // $limit = 'MysqlAdapter::TEXT_TINY';
            break;
        case 'mediumtext':
            // $limit = 'MysqlAdapter::TEXT_MEDIUM';
            break;
        case 'longtext':
            // $limit = 'MysqlAdapter::TEXT_LONG';
            break;
        case 'decimal':
            $pattern = '/decimal\((\d+),(\d+)\)$/';
            if ( preg_match($pattern, $columndata['Type'], $match) ) {
                $precision = $match[1];
                $scale = $match[2];
                $stringAttributes[] = "'precision' => {$precision}";
                $stringAttributes[] = "'scale' => {$scale}";
                $attributes['scale'] = $scale;
                $attributes['precision'] = $precision;
            }
            break;
        default:
            $pattern = '/\((\d+)\)$/';
            if (1 === preg_match($pattern, $columndata['Type'], $match)) {
                $limit = $match[1];
            }
    }
    if ($limit) {
        $stringAttributes[] = '\'limit\' => ' . $limit;
        $attributes['limit'] = $limit;
    }
    // unsigned
    $pattern = '/\(\d+\) unsigned$/';
    if (1 === preg_match($pattern, $columndata['Type'], $match)) {
        $stringAttributes[] = '\'signed\' => false';
        $attributes['signed'] = false;
    } else {
        $attributes['signed'] = true;
    }
    // enum values
    if ($type === 'enum') {
        $stringAttributes[] = '\'values\' => ' . str_replace('enum', 'array', $columndata['Type']);
        $attributes['enum'] = $columndata['Type'];
    }
    // [Key] => PRI [Extra] => auto_increment
    if ( isset($columndata['Key']) && $columndata['Key'] == 'PRI' && isset($columndata['Extra']) && $columndata['Extra'] == 'auto_increment' ) {
        $stringAttributes[] = '\'identity\' => true';
        $attributes['identity'] = true;
    }
    return [
        'string' => 'array(' . implode(', ', $stringAttributes) . ')',
        'attributes' => $attributes
    ];
}
function getColumns($table, $mysqli)
{
    $res = $mysqli->query('SHOW COLUMNS FROM ' . $table);
    return $res->fetch_all(MYSQLI_ASSOC);
}
function getIndexes($table, $mysqli)
{
    $res = $mysqli->query('SHOW INDEXES FROM ' . $table);
    return $res->fetch_all(MYSQLI_ASSOC);
}
function getForeignKeys($table, $mysqli)
{
    $res = $mysqli->query("SELECT
        cols.TABLE_NAME,
        cols.COLUMN_NAME,
        refs.REFERENCED_TABLE_NAME,
        refs.REFERENCED_COLUMN_NAME,
        cRefs.UPDATE_RULE,
        cRefs.DELETE_RULE
    FROM INFORMATION_SCHEMA.COLUMNS as cols
    LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS refs
        ON refs.TABLE_SCHEMA=cols.TABLE_SCHEMA
        AND refs.REFERENCED_TABLE_SCHEMA=cols.TABLE_SCHEMA
        AND refs.TABLE_NAME=cols.TABLE_NAME
        AND refs.COLUMN_NAME=cols.COLUMN_NAME
    LEFT JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS cons
        ON cons.TABLE_SCHEMA=cols.TABLE_SCHEMA
        AND cons.TABLE_NAME=cols.TABLE_NAME
        AND cons.CONSTRAINT_NAME=refs.CONSTRAINT_NAME
    LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS AS cRefs
        ON cRefs.CONSTRAINT_SCHEMA=cols.TABLE_SCHEMA
        AND cRefs.CONSTRAINT_NAME=refs.CONSTRAINT_NAME
    WHERE
        cols.TABLE_NAME = '" . $table . "'
        AND cols.TABLE_SCHEMA = DATABASE()
        AND refs.REFERENCED_TABLE_NAME IS NOT NULL
        AND cons.CONSTRAINT_TYPE = 'FOREIGN KEY'
    ;");
    return $res->fetch_all(MYSQLI_ASSOC);
}
function getIndentation($level)
{
    return str_repeat('    ', $level);
}

function debuglog($msg) {
    if ( is_object($msg) || is_array($msg) ) {
        $msg = print_r($msg, true);
    }
    file_put_contents('migration.log', $msg . "\n", FILE_APPEND);
}

debuglog(print_r($config, true));

// exit();

echo 'use Illuminate\Support\Facades\Schema;' . PHP_EOL;
echo 'use Illuminate\Database\Schema\Blueprint;' . PHP_EOL;
echo 'use Illuminate\Database\Migrations\Migration;' . PHP_EOL;

echo 'class CreateUserTable extends Migration' . PHP_EOL;
echo '{' . PHP_EOL;
echo '    /**' . PHP_EOL;
echo '     * Run the migrations.' . PHP_EOL;
echo '     *' . PHP_EOL;
echo '     * @return void' . PHP_EOL;
echo '     */' . PHP_EOL;
echo '    public function up()' . PHP_EOL;
echo '    {' . PHP_EOL;
echo createMigration(getMysqliConnection($config));
echo '    }' . PHP_EOL;
echo '}' . PHP_EOL;
