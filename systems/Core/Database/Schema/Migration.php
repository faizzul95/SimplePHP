<?php

namespace Core\Database\Schema;

use Core\Database\Interface\ForgeInterface;

/**
 * Migration — Base class for database migrations.
 *
 * Implements the ForgeInterface and provides a convenient structure
 * for creating reversible database changes.
 *
 * Usage:
 *   class CreateUsersTable extends Migration
 *   {
 *       public function up()
 *       {
 *           Schema::create('users', function (Blueprint $table) {
 *               $table->id();
 *               $table->string('name');
 *               $table->string('email')->unique();
 *               $table->string('password');
 *               $table->timestamps();
 *           });
 *       }
 *
 *       public function down()
 *       {
 *           Schema::dropIfExists('users');
 *       }
 *   }
 *
 * @category  Database
 * @package   Core\Database\Schema
 * @author    Mohd Fahmy Izwan Zulkhafri <faizzul14@gmail.com>
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0.0
 */
abstract class Migration implements ForgeInterface
{
    /**
     * @var string The table associated with this migration.
     */
    protected string $table = '';

    /**
     * @var string The database connection to use for this migration.
     */
    protected string $connection = 'default';

    /**
     * @var Grammars\SchemaGrammar|null Cached grammar instance.
     */
    private ?Grammars\SchemaGrammar $grammarCache = null;

    /**
     * Run the migration (create/modify structures).
     */
    abstract public function up(): void;

    /**
     * Reverse the migration.
     */
    abstract public function down(): void;

    /**
     * Create a table schema — delegates to Schema::create().
     *
     * @param string|Blueprint $schema Table name or Blueprint instance
     */
    public function create($schema)
    {
        if ($schema instanceof Blueprint) {
            $statements = $this->getGrammar()->compileCreate($schema);
            foreach ($statements as $sql) {
                $this->execute($sql);
            }
        }
    }

    /**
     * Alter a table schema — delegates to Schema::table().
     *
     * @param string|Blueprint $schema Table name or Blueprint instance
     */
    public function alter($schema)
    {
        if ($schema instanceof Blueprint) {
            $statements = $this->getGrammar()->compileAlter($schema);
            foreach ($statements as $sql) {
                $this->execute($sql);
            }
        }
    }

    /**
     * Get the grammar for the current connection's driver.
     */
    protected function getGrammar(): Grammars\SchemaGrammar
    {
        if ($this->grammarCache !== null) {
            return $this->grammarCache;
        }

        $driver = 'mysql';
        if (function_exists('db') && db($this->connection) !== null) {
            $driverProp = db($this->connection)->getDriver();
            if ($driverProp) {
                $driver = strtolower($driverProp);
            }
        }

        $this->grammarCache = \Core\Database\DriverRegistry::schemaGrammar($driver);

        return $this->grammarCache;
    }

    /**
     * Execute a SQL statement on the migration connection.
     */
    protected function execute(string $sql): void
    {
        if (function_exists('db')) {
            db($this->connection)->query($sql)->execute();
        }
    }
}
