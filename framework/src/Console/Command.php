<?php
namespace Bow\Console;

use Bow\Resource\Storage;
use Bow\Support\Collection;
use Bow\Support\Str;

class Command
{
    /**
     * @var string
     */
    const BAD_COMMAND = "Bad command.%sPlease type this command \033[0;32;7m`php bow help` or `php bow command help` for more information.";

    /**
     * @var string
     */
    private $dirname;

    /**
     * @var array
     */
    private $options = [];

    /**
     * Command constructor.
     *
     * @param string $dirname
     */
    public function __construct($dirname)
    {
        $this->dirname = $dirname;
        $this->formatParameters();
    }

    /**
     * Permet de formater les options
     *
     * @return mixed
     */
    public function formatParameters()
    {
        foreach ($GLOBALS['argv'] as $key => $param) {
            if ($key == 0) {
                continue;
            }

            if ($key == 1) {
                if (preg_match('/^[a-z]+:[a-z]+$/', $param)) {
                    $part = explode(':', $param);
                    $this->options['command'] = $part[0];
                    $this->options['action'] = $part[1];
                    continue;
                }
                $this->options['command'] = $param;
                continue;
            }

            if ($key == 2) {
                if (preg_match('/^[a-z_]+$/i', $param)) {
                    $this->options['target'] = $param;
                    continue;
                }
            }

            if (preg_match('/^--[a-z-]+$/', $param)) {
                $this->options['options'][$param] = true;
                continue;
            }

            if (count($part = explode('=', $param)) == 2) {
                $this->options['options'][$part[0]] = $part[1];
                continue;
            }

            $this->options['trash'][] = $param;
        }

        if (isset($this->options['options'])) {
            $this->options['options'] = new Collection($this->options['options']);
        }
    }


    /**
     * Permet de récupérer un parametre
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed|Collection|null
     */
    public function getParameter($key, $default = null)
    {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    /**
     * Permet de récupérer les options de la commande
     *
     * @param  string $key
     * @param  string $default
     * @return Collection|mixed|null
     */
    public function options($key = null, $default = null)
    {
        $option = $this->getParameter('options', new Collection());

        if ($key == null) {
            return $option;
        }

        return $option->get($key, $default);
    }


    /**
     * Permet de monter une migration
     *
     * @param string $model
     */
    public function up($model)
    {
        $this->makeMigration($model, 'up');
    }

    /**
     * Permet supprimer une migration dans la base de donnée
     *
     * @param string $model
     */
    public function down($model)
    {
        $this->makeMigration($model, 'down');
    }

    /**
     * Permet de rafraichir le fichier de régistre
     */
    public function reflesh()
    {
        $register = $this->dirname.'/db/migration/.registers';
        file_put_contents($register, '');

        $files = glob($this->dirname.'/db/migration/*.php');

        foreach ($files as $file) {
            $parts = preg_split('/([0-9_])+/', basename($file));
            array_shift($parts);
            $className = '';
            
            foreach ($parts as $part) {
                $className .= ucfirst(str_replace('.php', '', $part));
            }

            file_put_contents($register, basename(str_replace('.php', '', $file))."|".$className."\n", FILE_APPEND);
        }
    }

    /**
     * Permet de créer une migration dans les deux directions
     * 
     * @param $model
     * @param $type
     * 
     * @throws \ErrorException
     */
    private function makeMigration($model, $type)
    {
        $options = $this->options();
        $param = [];

        if ($options->has('--display-sql') && $options->get('--display-sql') === true) {
            $param = [true];
        }

        if ($type == 'down') {
            if (is_null($model)) {
                if ($options->get('--all') === null) {
                    echo Color::danger("cette commande est super dangereuse. Alors veuillez ajout le flag --all pour assurer bow.");
                    exit(1);
                }
            }
        }

        if (!is_null($model)) {
            $model = strtolower($model);
            $fileParten = $this->dirname.strtolower("/db/migration/*{$model}*.php");
        } else {
            $fileParten = $this->dirname.strtolower("/db/migration/*.php");
        }

        $register = ["file" => [], "tables" => []];

        if (!file_exists($this->dirname."/db/migration/.registers")) {
            echo Color::red('Le fichier de registre de bow est introvable.');
            exit(0);
        }

        $registers = file($this->dirname."/db/migration/.registers");

        if (count($registers) == 0) {
            echo Color::red('Le fichier de registre de bow est vide.');
            exit(0);
        }

        foreach (file($this->dirname."/db/migration/.registers") as $r) {
            $tmp = explode("|", $r);
            $register["file"][] = $tmp[0];
            $register["tables"][] = $tmp[1];
        }

        foreach (glob($fileParten) as $file) {
            if (!file_exists($file)) {
                echo Color::red("$file n'existe pas.");
                exit();
            }

            // Collection des fichiers de migration.
            $filename = preg_replace("@^(".$this->dirname."/migration/)|(\.php)$@", "", $file);

            if (in_array($filename, $register["file"])) {
                $num = array_flip($register["file"])[$filename];
                $model = rtrim($register["tables"][$num]);
            }

            include $file;

            // Formatage de la classe et Exécution de la méthode up ou down
            $class = ucfirst(Str::camel($model));

            if (!class_exists($class)) {
                echo Color::red("Classe \"{$class}\" introvable. Vérifiez vos fichier de régistre.");
                exit(1);
            }

            $instance = new $class;

            call_user_func_array([$instance, strtolower($type)], $param);
        }

        exit(0);
    }

    /**
     * Permet de créer un seeder
     * 
     * @param $name
     */
    public function seeder($name)
    {
        $seeder_filename = $this->dirname."/db/seeders/{$name}_seeder.php";

        if (file_exists($seeder_filename)) {
            echo "\033[0;31mLe seeder '$name' exists déja.\033[00m";
            exit(1);
        }

        $options = $this->options();
        $num = 5;

        if ($options->has('--n-seed') && is_int($options->get('--n-seed'))) {
            $num = $options->get('--n-seed', 5);
        }

        $content = <<<SEEDER
<?php

\$seeds = [];

foreach (range(1, $num) as \$key) {
    \$seeds[] = [
        'id' => faker('autoincrement'),
        'created_at' => faker('date'),
        'update_at' => faker('date')
    ];
}

return ['$name' => \$seeds];
SEEDER;
        file_put_contents($seeder_filename, $content);
        echo "\033[0;32mLe seeder \033[00m[$name]\033[0;32m a été bien crée.\033[00m\n";
        exit(0);
    }

    /**
     * Permet de crée une migration
     *
     * @param  $model
     * @throws \ErrorException
     */
    public function make($model)
    {
        $mapMethod = ["create", "drop"];
        $table = $model;

        $options = $this->options();

        if (file_exists($this->dirname."/db/migration/.registers")) {
            @touch($this->dirname."/db/migration/.registers");
        }

        if ($options->has('--create') && $options->has('--table')) {
            throw new \ErrorException('Bad command.');
        }

        if ($options->has('--table')) {
            if ($options->get('--table') === true) {
                throw new \ErrorException(sprintf(self::BAD_COMMAND, ' [--table] '));
            }
            $table = $options->get('--table');
            $mapMethod = ["table", "drop"];
        }

        if ($options->has('--create')) {
            if ($options->get('--create') === true) {
                throw new \ErrorException(sprintf(self::BAD_COMMAND, ' [--create] '));
            }
            $table = $options->get('--create');
            $mapMethod = ["create", "drop"];
        }

        $class_name = ucfirst(Str::camel($model));
        $migrate = <<<doc
<?php
use \Bow\Database\Migration\Schema;
use \Bow\Database\Migration\Migration;
use \Bow\Database\Migration\TablePrinter as Printer;

class {$class_name} extends Migration
{
    /**
     * create Table
     */
    public function up()
    {
        Schema::{$mapMethod[0]}("$table", function(Printer \$table) {
            \$table->increment('id');
            \$table->timestamps();
        });
    }

    /**
     * Drop Table
     */
    public function down()
    {
        Schema::{$mapMethod[1]}("$table");
    }
}
doc;
        $create_at = date("Y_m_d") . "_" . date("His");
        file_put_contents($this->dirname."/db/migration/${create_at}_${model}.php", $migrate);
        Storage::append($this->dirname."/db/migration/.registers", "${create_at}_${model}|$class_name\n");

        echo "\033[0;32mmLe file de migration \033[00m[$model]\033[0;32m a été bien crée.\033[00m\n";
        return;
    }

    /**
     * Permet de mettre en place le système de réssource.
     *
     * @param string $controller_name
     */
    public function resource($controller_name)
    {
        $path = preg_replace("/controller/", "", strtolower($controller_name));
        $filename = $this->dirname."/app/Controllers/${controller_name}.php";

        if (file_exists($filename)) {
            echo Color::danger('Le controlleur existe déja');
            exit(1);
        }

        $model = ucfirst($path);
        $modelNamespace = '';


        if (static::readline("Voulez vous que je crée les vues associées?")) {
            $model = strtolower($model);
            @mkdir($this->dirname."/components/views/".$model, 0766);

            echo "\033[0;33;7m";
            foreach (["create", "edit", "show", "index", "update", "delete"] as $value) {
                $file = $this->dirname."/components/views/$model/$value.twig";
                echo "$file\n";
            }
            echo "\033[00m";
        }

        $options = $this->options();

        if ($this->readline("Voulez vous que je crée un model?")) {
            if ($options->has('--model')) {
                if ($options->get('--model') !== true) {
                    $model = $options->get('--model');
                } else {
                    echo "\033[0;32;7mLe nom du model non spécifié --model=model_name.\033[00m\n";
                    exit(1);
                }
            }

            $this->model($model);
            $modelNamespace = "\nuse App\\".ucfirst($model).';';

            if ($this->readline('Voulez vous que je crée une migration pour ce model? ')) {
                $this->make($model);
            }
        }

        $controllerRestTemplate =<<<CC
<?php
namespace App\Controllers;
$modelNamespace
use Bow\Database\Database;

class {$controller_name} extends Controller
{
    /**
     * Point d'entré
     * GET /$path
     *
     * @param mixed \$id [optional] L'identifiant de l'element à récupérer
     * @return mixed
     */
    public function index(\$id = null)
    {
        // Codez Ici
    }

    /**
     * Permet d'afficher la vue permettant de créer une résource.
     *
     * GET /$path/create
     */
    public function create()
    {
        // Codez Ici
    }

    /**
     * Permet d'ajouter une nouvelle résource dans la base d'information
     *
     * POST /$path
     */
    public function store()
    {
        // Codez Ici
    }

    /**
     * Permet de récupérer un information précise avec un identifiant.
     *
     * GET /$path/:id
     *
     * @param mixed \$id L'identifiant de l'élément à récupérer
     * @return mixed
     */
    public function show(\$id)
    {
        // Codez Ici
    }

    /**
     * Mise à jour d'un résource en utilisant paramètre du GET
     *
     * GET /$path/:id/edit
     *
     * @param mixed \$id L'identifiant de l'élément à mettre à jour
     * @return mixed
     */
    public function edit(\$id)
    {
        // Codez Ici
    }

    /**
     * Mise à jour d'une résource
     *
     * PUT /$path/:id
     *
     * @param mixed \$id L'identifiant de l'élément à mettre à jour
     * @return mixed
     */
    public function update(\$id)
    {
        // Codez Ici
    }

    /**
     * Permet de supprimer une resource
     *
     * DELETE /$path/:id
     *
     * @param mixed \$id L'identifiant de l'élément à supprimer
     * @return mixed
     */
    public function destroy(\$id)
    {
        // Codez Ici
    }
}
CC;
        file_put_contents($this->dirname."/app/Controllers/${controller_name}.php", $controllerRestTemplate);
        echo "\033[0;32mLe controlleur \033[00m[{$controller_name}]\033[0;32m a été bien crée.\033[00m\n";
        exit(0);
    }

    /**
     * Create new controller file
     *
     * @param string $controller_name
     */
    public function controller($controller_name)
    {
        if (!preg_match("/controller$/i", $controller_name)) {
            $controller_name = ucfirst($controller_name) . "Controller";
        } else {
            if (preg_match("/^(.+)(controller)$/", $controller_name, $match)) {
                array_shift($match);
                $controller_name = ucfirst($match[0]) . ucfirst($match[1]);
            } else {
                $controller_name = ucfirst($controller_name);
            }
        }

        if (file_exists($this->dirname."/app/Controllers/$controller_name.php")) {
            echo "\033[0;31mLe controlleur \033[0;33m\033[0;31m[$controller_name]\033[00m\033[0;31m existe déja.\033[00m\n";
            exit(1);
        }

        if ($this->options('--no-plain')) {
            $content = <<<CONTENT
    
    /**
     * Point d'entré de l'application
     *
     * @return mixed
     */
    public function index()
    {
        // Codez Ici
    }

    /**
     * Permet d'afficher la vue permettant de créer une résource.
     */
    public function create()
    {
        // Codez Ici
    }

    /**
     * Permet d 'ajouter une nouvelle résource dans la base d'information
     */
    public function store()
    {
        // Codez Ici
    }

    /**
     * Permet de récupérer un information précise avec un identifiant.
     *
     * @param  mixed \$id L'identifiant de l'élément à récupérer
     * 
     * @return mixed
     */
    public function show(\$id)
    {
        // Codez Ici
    }

    /**
     * Mise à jour d'un résource en utilisant paramètre du GET
     *
     * @param mixed \$id L'identifiant de l'élément à mettre à jour
     * 
     * @return mixed
     */
    public function edit(\$id)
    {
        // Codez Ici
    }

    /**
     * Mise à jour d'une résource
     *
     * @param mixed \$id L'identifiant de l'élément à mettre à jour
     * 
     * @return mixed
     */
    public function update(\$id)
    {
        // Codez Ici
    }

    /**
     * Permet de supprimer une resource
     *
     * @param mixed \$id L'identifiant de l'élément à supprimer
     * 
     * @return mixed
     */
    public function destroy(\$id)
    {
        // Codez Ici
    }
CONTENT;
        } else {
            $content = <<<CONTENT
// Écrivez votre code ici
CONTENT;
        }

        $controller_template =<<<CC
<?php
namespace App\Controllers;

class {$controller_name} extends Controller
{
    $content
}
CC;
        file_put_contents($this->dirname."/app/Controllers/${controller_name}.php", $controller_template);
        echo "\033[0;32mLe controlleur \033[00m\033[1;33m[$controller_name]\033[00m\033[0;32m a été bien crée.\033[00m\n";
        exit(0);
    }

    /**
     * @param $middleware_name
     * @return int
     */
    public function middleware($middleware_name)
    {
        $middleware_name = ucfirst($middleware_name);

        if (file_exists($this->dirname."/app/Middleware/$middleware_name.php")) {
            echo "\033[0;31mLe middleware \033[0;33m\033[0;31m[$middleware_name]\033[00m\033[0;31m existe déja.\033[00m\n";
            exit(1);
        }

        $middleware_template = <<<CM
<?php
namespace App\Middleware;

class {$middleware_name}
{
    /**
     * Fonction de lancement du middleware.
     * 
     * @param \\Bow\\Http\\Request \$request
     * @param callable \$next
     * @return boolean
     */
    public function checker(\$request, callable \$next, \$guard)
    {
        // Codez Ici
        return \$next();
    }
}
CM;
        @mkdir($this->dirname."/app/Middleware");
        file_put_contents($this->dirname."/app/Middleware/$middleware_name.php", $middleware_template);
        echo "\033[0;32mLe middleware \033[00m[{$middleware_name}]\033[0;32m a été bien crée.\033[00m\n";

        exit(0);
    }

    /**
     * Create new model file
     *
     * @param  string      $model_name
     * @param  string|null $table_name
     * @return int
     */
    public function model($model_name, $table_name = null)
    {
        $model_name = ucfirst(Str::camel($model_name));
        $model = <<<MODEL
<?php
namespace App;

use Bow\Database\Barry\Model;

class ${model_name} extends Model
{
    //
}
MODEL;
        if (file_exists($this->dirname."/app/${model_name}.php")) {
            echo "\033[0;33mLe model \033[0;33m\033[0;31m[${model_name}]\033[00m\033[0;31m existe déja.\033[00m\n";
            exit(1);
        }

        file_put_contents($this->dirname."/app/${model_name}.php", $model);
        echo "\033[0;32mLe model \033[00m[${model_name}]\033[0;32m a été bien crée.\033[00m\n";

        if ($this->options('-m')) {
            $this->make('create_'.strtolower($model_name).'_table');
        }

        exit(0);
    }

    /**
     * Permet de générer la clé de securité
     */
    public function key()
    {
        $key = base64_encode(openssl_random_pseudo_bytes(12) . date('Y-m-d H:i:s') . microtime(true));
        file_put_contents($this->dirname."/config/.key", $key);

        echo "Application key => \033[0;32m$key\033[00m\n";
        exit;
    }

    /**
     * Permet de créer un validator
     *
     * @param  string $name
     * @return int
     */
    public function validation($name)
    {
        if (!is_dir($this->dirname.'/app/Validation')) {
            mkdir($this->dirname.'/app/Validation');
        }

        if (!preg_match('/validator/i', $name)) {
            $name = ucfirst($name);
        }

        if (file_exists($this->dirname.'/app/Validation/'.$name.'.php')) {
            echo "\033[0;33mLe validateur \033[0;33m\033[0;31m[${name}]\033[00m\033[0;31m existe déja.\033[00m\n";
            return 0;
        }

        $validation = <<<VALIDATOR
<?php

namespace App\Validation;

use Bow\Validation\ValidationRequest as Validator;

class {$name} extends Validator
{
    /**
     * Permet de verifier la permission d'un utilisateur 
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }
    
	/**
	 * Règle de validation
	 * 
	 * @var array
	 */
	protected function rules() {
	    return [
            // Vos régles
        ];
    }
        
	/**
	 * Liste des clés à valider
	 * 
	 * @var array
	 */
	protected function keys()
	{
	    return ['*']
	}
}
VALIDATOR;

        file_put_contents($this->dirname.'/app/Validation/'.$name.'.php', $validation);
        echo "\033[0;32mLe validateur \033[00m[${name}]\033[0;32m a été bien crée.\033[00m\n";
        return 0;
    }

    /**
     * Permet de créer un validator
     *
     * @param  string $name
     * @return int
     */
    public function service($name)
    {
        if (!is_dir($this->dirname.'/app/Services')) {
            mkdir($this->dirname.'/app/Services');
        }

        if (!preg_match('/service/i', $name)) {
            $name = ucfirst($name).'Service';
        }

        if (file_exists($this->dirname.'/app/Services/'.$name.'.php')) {
            echo "\033[0;33mLe service \033[0;33m\033[0;31m[${name}]\033[00m\033[0;31m existe déja.\033[00m\n";
            return 0;
        }

        $validation = <<<VALIDATOR
<?php

namespace App\Services;

use Bow\Config\Config;
use Bow\Application\Services as BowService;

class {$name} extends BowService
{
    /**
     * Démarre le serivce
     */
    public function start()
    {
        //
    }

    /**
     * @param Config \$config
     */
    public function make(Config \$config)
    {
        //
    }
}
VALIDATOR;

        file_put_contents($this->dirname.'/app/Services/'.$name.'.php', $validation);
        echo "\033[0;32mLe service \033[00m[${name}]\033[0;32m a été bien crée.\033[00m\n";
        return 0;
    }

    /**
     * Read ligne
     *
     * @param  string $message
     * @return bool
     */
    private function readline($message)
    {
        echo "\033[0;32m$message y/N\033[00m >>> ";

        $input = strtolower(trim(readline()));

        if (in_array($input, ['y', 'n'])) {
            echo Color::red('Choix invalide');
            return false;
        }

        if (strtolower($input) == "y") {
            return true;
        }

        if (strtolower($input) == 'n' || strlen($input) == 0) {
            return false;
        }

        return false;
    }
}
