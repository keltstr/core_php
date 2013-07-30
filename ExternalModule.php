<?php
namespace samson\core;

/**
 * SamsonPHP external module
 *
 * @author Vitaly Iegorov <vitalyiegorov@gmail.com> 
 * @version 0.1
 */
class ExternalModule extends Module implements iExternalModule
{		
	/** Указатель на родительский модуль */
	public $parent = NULL;
	
	/** Коллекция связанных модулей с текущим */
	protected $requirements = array();
	
	/** Конструктор */
	public function  __construct( $path = NULL )
	{	
		// Module identifier not specified - set it to NameSpace\Classname
		if( !isset( $this->id{0} )) $this->id = strtolower(get_class($this));
		
		//[PHPCOMPRESSOR(remove,start)]
		// Создадим конфигурацию для composer
		$this->composer();
		//[PHPCOMPRESSOR(remove,end)]	

		parent::__construct( $this->id, $path );
	}
	
	/** Обработчик сериализации объекта */
	public function __sleep()
	{
		// Remove all unnessesary fields from serialization
		return array_diff( array_keys( get_object_vars( $this )), array( 'view_path', 'view_html', 'view_data' ));
	}
	
	/** @see Module::duplicate() */
	public function & duplicate( $id, $class_name = null )
	{
		// Вызовем родительский метод
		$m = parent::duplicate( $id, $class_name );
	
		// Привяжем родительский модуль к дубликату
		$m->parent = & $this->parent;		
	
		// Вернем дубликат
		return $m;
	}	
	
	/**
	 * Перегружаем стандартное поведение выполнения действия контроллера
	 * Если текущий модуль наследует другой <code>ModuleConnector</code>
	 * то тогда сначала выполняется действие контроллера в данном модуле,
	 * а потом в его родителе. Это дает возможность выполнять наследование
	 * контроллеров модулей.
	 *
	 * @see iModule::action()
	 */
	public function action( $method_name = NULL )
	{
		// Выполним стандартное действие
		$result = parent::action( $method_name );
	
		// Если мы не смогли выполнить действие для текущего модуля
		// и задан родительский модуль
		if( $result === A_FAILED && isset($this->parent) )
		{
			// Выполним действие для родительского модуля
			return $this->parent->action( $method_name );
		}
	
		// Веренем результат выполнения действия
		return $result;
	}
	
	/**
	 * Перегружаем стандартное поведение вывода представления модуля
	 * Если текущий модуль наследует другой <code>ModuleConnector</code>
	 * то тогда сначала выполняется проверка существования требуемого представление
	 * в данном модуле, а потом в его родителе. Это дает возможность выполнять наследование
	 * представлений модулей.
	 *
	 * @see Module::output()
	 */
	public function output( $view_path = null )
	{
		// Если этот класс не прямой наследник класса "Подключаемого Модуля"
		if( isset($this->parent) )
		{					
			// Построим путь к представлению относительно текущего модуля
			$path = $this->path.$view_path;
				
			// Если требуемое представление НЕ существует в текущем модуле -
			// выполним вывод представления для родительского модуля
			if( ! file_exists( $path ) && ! isset($GLOBALS['__compressor_files'][ $path ]) )
			{
				return $this->view_html.$this->parent->output( $view_path, $this->data );
			}
		}
	
		// Просто выполним родительский метод
		return parent::output( $view_path );
	}
	
	/**	@see iModuleConnector::prepare() */
	public function prepare()
	{		
		// Переберем все связи модуля
		foreach ( $this->requirements as $module => $version )
		{
			// Поумолчания установим такое отношение по версии модуля
			$version_sign = '>=';
				
			// Если не указана версия модуля - правильно получим имя модуля и установим версию
			if( !isset( $module{0} ) ) { $module = $version; $version = '0.0.1'; }
	
			// Определим версию и знаки
			if( preg_match( '/\s*((?<sign>\>\=|\<\=\>|\<)*(?<version>.*))\s*/iu', $version, $matches ))
			{
				$version_sign = isset($matches['sign']{0}) ? $matches['sign'] : $version_sign;
				$version = $matches['version'];
			}
	
			// Получим регистро не зависимое имя модуля
			$_module = mb_strtolower( $module, 'UTF-8' );			
				
			// Проверим загружен ли требуемый модуль в ядро
			if( !isset( Module::$instances[ $_module ] ) )
			{				
				return e( 'Failed loading module(##) - Required module(##) not found', E_SAMSON_FATAL_ERROR, array( $this->id, $module) );
			}
			// Модуль определен сравним его версию
			else if ( version_compare( Module::$instances[ $_module ]->version, $version, $version_sign ) === false )
			{
				return e( 'Ошибка загрузки модуля(##) в ядро - Версия связанного модуля(##) не подходит ## ## ##',
						E_SAMSON_FATAL_ERROR,
						array(
								$this->id,
								$_module,
								$version,
								$version_sign,
								Module::$instances[ $_module ]->version
						)
				);
			}
		}
	
		// Вернем результат проверки модуля
		return TRUE;
	}
	
	/**	@see iModuleConnector::init() */
	public function init( array $params = array() )
	{
		// Установим переданные параметры
		$this->set( $params );
	
		return TRUE;
	}
	
	/** Создать файл конфигурации для composer */
	private function composer()
	{
		// Преобразуем массив зависисмостей в объект
		$require = new \stdClass();
	
		// Обработаем список зависимостей
		foreach ( $this->requirements as $k => $v )
		{
			if(!is_int($k)) $require->$k = $v;
			else $require->$v = '*.*.*';
		}
	
		// Сформируем файл-конфигурацию для composer
		$composer = str_replace('\\\\', '/', json_encode( array(
				'name'		=> dirname(get_class($this)),
				'author' 	=> $this->author,
				'version'	=> $this->version,
				'require'	=> $require
		), 64 ));
	
		// Проверим если файл конфигурации для composer не существует или конфигурация изменилась
		if( ! file_exists( $this->path.'/composer.json' ) || ( md5(file_get_contents( $this->path.'/composer.json' )) != md5($composer)) )
		{
			// Запишем файл конфигурации composer
			file_put_contents( $this->path.'/composer.json', $composer );
		}
	}
}