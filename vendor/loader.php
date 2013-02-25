<?php
/**
 * @package    Joomla.Platform
 *
 * @copyright  Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */


/**
 * Static class to handle loading of libraries.
 *
 * @package  Joomla.Platform
 * @since    11.1
 */
abstract class JLoader
{
	const LOWER_CASE = 1;
	const NATURAL_CASE = 2;
	const MIXED_CASE = 3;

	/**
	 * Holds the map of old class to their namespaced
	 * counterparts if self::$compat = true.
	 *
	 * @var    array
	 * @since  13.1
	 */
	protected static $nsMap = array();

	/**
	 * Container for already imported library paths.
	 *
	 * @var    array
	 * @since  11.1
	 */
	protected static $classes = array();

	/**
	 * Container for already imported library paths.
	 *
	 * @var    array
	 * @since  11.1
	 */
	protected static $imported = array();

	/**
	 * Container for registered library class prefixes and path lookups.
	 *
	 * @var    array
	 * @since  12.1
	 */
	protected static $prefixes = array();

	/**
	 * Container for namespace => path map.
	 *
	 * @var    array
	 * @since  12.3
	 */
	protected static $namespaces = array();

	/**
	 * Method to discover classes of a given type in a given path.
	 *
	 * @param   string   $classPrefix  The class name prefix to use for discovery.
	 * @param   string   $parentPath   Full path to the parent folder for the classes to discover.
	 * @param   boolean  $force        True to overwrite the autoload path value for the class if it already exists.
	 * @param   boolean  $recurse      Recurse through all child directories as well as the parent path.
	 *
	 * @return  void
	 *
	 * @since   11.1
	 */
	public static function discover($classPrefix, $parentPath, $force = true, $recurse = false)
	{
		try
		{
			if ($recurse)
			{
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($parentPath),
					RecursiveIteratorIterator::SELF_FIRST
				);
			}
			else
			{
				$iterator = new DirectoryIterator($parentPath);
			}

			foreach ($iterator as $file)
			{
				$fileName = $file->getFilename();

				// Only load for php files.
				// Note: DirectoryIterator::getExtension only available PHP >= 5.3.6
				if ($file->isFile() && substr($fileName, strrpos($fileName, '.') + 1) == 'php')
				{
					// Get the class name and full path for each file.
					$class = strtolower($classPrefix . preg_replace('#\.php$#', '', $fileName));

					// Register the class with the autoloader if not already registered or the force flag is set.
					if (empty(self::$classes[$class]) || $force)
					{
						self::register($class, $file->getPath() . '/' . $fileName);
					}
				}
			}
		}
		catch (UnexpectedValueException $e)
		{
			// Exception will be thrown if the path is not a directory. Ignore it.
		}
	}

	/**
	 * Method to get the list of registered classes and their respective file paths for the autoloader.
	 *
	 * @return  array  The array of class => path values for the autoloader.
	 *
	 * @since   11.1
	 */
	public static function getClassList()
	{
		return self::$classes;
	}

	/**
	 * Method to get the list of registered namespaces.
	 *
	 * @return  array  The array of namespace => path values for the autoloader.
	 *
	 * @since   12.3
	 */
	public static function getNamespaces()
	{
		return self::$namespaces;
	}

	/**
	 * Loads a class from specified directories.
	 *
	 * @param   string  $key   The class name to look for (dot notation).
	 * @param   string  $base  Search this directory for the class.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   11.1
	 */
	public static function import($key, $base = null)
	{
		// Only import the library if not already attempted.
		if (!isset(self::$imported[$key]))
		{
			// Setup some variables.
			$success = false;
			$parts = explode('.', $key);
			$class = array_pop($parts);
			$base = (!empty($base)) ? $base : __DIR__;
			$path = str_replace('.', DIRECTORY_SEPARATOR, $key);

			// Handle special case for helper classes.
			if ($class == 'helper')
			{
				$class = ucfirst(array_pop($parts)) . ucfirst($class);
			}
			// Standard class.
			else
			{
				$class = ucfirst($class);
			}

			// If we are importing a library from the Joomla namespace set the class to autoload.
			if (strpos($path, 'joomla') === 0)
			{
				// Since we are in the Joomla namespace prepend the classname with J.
				$class = 'J' . $class;

				// Only register the class for autoloading if the file exists.
				if (is_file($base . '/' . $path . '.php'))
				{
					self::$classes[strtolower($class)] = $base . '/' . $path . '.php';
					$success = true;
				}
			}
			/*
			 * If we are not importing a library from the Joomla namespace directly include the
			* file since we cannot assert the file/folder naming conventions.
			*/
			else
			{
				// If the file exists attempt to include it.
				if (is_file($base . '/' . $path . '.php'))
				{
					$success = (bool) include_once $base . '/' . $path . '.php';
				}
			}

			// Add the import key to the memory cache container.
			self::$imported[$key] = $success;
		}

		return self::$imported[$key];
	}

	/**
	 * Load the file for a class.
	 *
	 * @param   string  $class  The class to be loaded.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   11.1
	 */
	public static function load($class)
	{
		// Sanitize class name.
		$class = strtolower($class);

		// If the class already exists do nothing.
		if (class_exists($class, false))
		{
			return true;
		}

		// If the class is registered include the file.
		if (isset(self::$classes[$class]))
		{
			include_once self::$classes[$class];

			return true;
		}

		return false;
	}

	/**
	 * Load a class based on namespace using the Lower Case strategy.
	 * This loader might be used when the namespace is lower case or camel case
	 * and the path lower case.
	 *
	 * @param   string  $class  The class (including namespace) to load.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   12.3
	 */
	public static function loadByNamespaceLowerCase($class)
	{
		// If the class already exists do nothing.
		if (class_exists($class, false))
		{
			return true;
		}

		// Get the root namespace name.
		$namespace = strstr($class, '\\', true);

		// If we find the namespace in the stack.
		if (isset(self::$namespaces[$namespace]))
		{
			// Remove the namespace name from the class.
			$class = str_replace($namespace, '', $class);

			// Create a lower case relative path.
			$relativePath = strtolower(str_replace('\\', '/', $class));

			// Iterate the registered root paths.
			foreach (self::$namespaces[$namespace] as $rootPath)
			{
				// Create the full path.
				$path = $rootPath . '/' . $relativePath . '.php';

				// Include the file if it exists.
				if (file_exists($path))
				{
					return (bool) include_once $path;
				}
			}
		}

		return false;
	}

	/**
	 * Load a class based on namespace using the Natural Case strategy.
	 * This loader might be used when the namespace case matches the path case.
	 *
	 * @param   string  $class  The class (including namespace) to load.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   12.3
	 */
	public static function loadByNamespaceNaturalCase($class)
	{
		// If the class already exists do nothing.
		if (class_exists($class, false))
		{
			return true;
		}

		// Get the root namespace name.
		$namespace = strstr($class, '\\', true);

		// If we find the namespace in the stack.
		if (isset(self::$namespaces[$namespace]))
		{
			// Remove the namespace name from the class.
			$class = str_replace($namespace, '', $class);

			// Create a relative path.
			$relativePath = str_replace('\\', '/', $class);

			// Iterate the registered root paths.
			foreach (self::$namespaces[$namespace] as $rootPath)
			{
				// Create the full path.
				$path = $rootPath . '/' . $relativePath . '.php';

				// Include the file if it exists.
				if (file_exists($path))
				{
					return (bool) include_once $path;
				}
			}
		}

		return false;
	}

	/**
	 * Load a class based on namespace using the Mixed Case strategy.
	 * This loader might be used when the namespace case matches the path case,
	 * or when the namespace is camel case and the path lower case.
	 *
	 * @param   string  $class  The class (including namespace) to load.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   12.3
	 */
	public static function loadByNamespaceMixedCase($class)
	{
		// If the class already exists do nothing.
		if (class_exists($class, false))
		{
			return true;
		}

		// Get the root namespace name.
		$namespace = strstr($class, '\\', true);

		// If we find the namespace in the stack.
		if (isset(self::$namespaces[$namespace]))
		{
			// Remove the namespace name from the class.
			$class = str_replace($namespace, '', $class);

			// Create a relative path.
			$relativePath = str_replace('\\', '/', $class);

			// Create a relative lower case path.
			$relativeLowPath = strtolower($relativePath);

			// Iterate the registered root paths.
			foreach (self::$namespaces[$namespace] as $rootPath)
			{
				// Create the full lower case path.
				$lowerPath = $rootPath . '/' . $relativeLowPath . '.php';

				// Include the file if it exists.
				if (file_exists($lowerPath))
				{
					return (bool) include_once $lowerPath;
				}

				// Create the full natural case path.
				$naturalPath = $rootPath . '/' . $relativePath . '.php';

				// Include the file if it exists.
				if (file_exists($naturalPath))
				{
					return (bool) include_once $naturalPath;
				}
			}
		}

		return false;
	}

	/**
	 * Directly register a class to the autoload list.
	 *
	 * @param   string   $class  The class name to register.
	 * @param   string   $path   Full path to the file that holds the class to register.
	 * @param   boolean  $force  True to overwrite the autoload path value for the class if it already exists.
	 *
	 * @return  void
	 *
	 * @since   11.1
	 */
	public static function register($class, $path, $force = true)
	{
		// Sanitize class name.
		$class = strtolower($class);

		// Only attempt to register the class if the name and file exist.
		if (!empty($class) && is_file($path))
		{
			// Register the class with the autoloader if not already registered or the force flag is set.
			if (empty(self::$classes[$class]) || $force)
			{
				self::$classes[$class] = $path;
			}
		}
	}

	/**
	 * Register a class prefix with lookup path.  This will allow developers to register library
	 * packages with different class prefixes to the system autoloader.  More than one lookup path
	 * may be registered for the same class prefix, but if this method is called with the reset flag
	 * set to true then any registered lookups for the given prefix will be overwritten with the current
	 * lookup path. When loaded, prefix paths are searched in a "last in, first out" order.
	 *
	 * @param   string   $prefix  The class prefix to register.
	 * @param   string   $path    Absolute file path to the library root where classes with the given prefix can be found.
	 * @param   boolean  $reset   True to reset the prefix with only the given lookup path.
	 *
	 * @return  void
	 *
	 * @throws  RuntimeException
	 *
	 * @since   12.1
	 */
	public static function registerPrefix($prefix, $path, $reset = false)
	{
		// Verify the library path exists.
		if (!file_exists($path))
		{
			throw new RuntimeException('Library path ' . $path . ' cannot be found.', 500);
		}

		// If the prefix is not yet registered or we have an explicit reset flag then set set the path.
		if (!isset(self::$prefixes[$prefix]) || $reset)
		{
			self::$prefixes[$prefix] = array($path);
		}
		// Otherwise we want to simply add the path to the prefix.
		else
		{
			array_unshift(self::$prefixes[$prefix], $path);
		}
	}

	/**
	 * Register a namespace to the autoloader. When loaded, namespace paths are searched in a "last in, first out" order.
	 *
	 * @param   string   $namespace  A case sensitive Namespace to register.
	 * @param   string   $path       A case sensitive absolute file path to the library root where classes of the given namespace can be found.
	 * @param   boolean  $reset      True to reset the namespace with only the given lookup path.
	 *
	 * @return  void
	 *
	 * @throws  RuntimeException
	 *
	 * @since   12.3
	 */
	public static function registerNamespace($namespace, $path, $reset = false)
	{
		// Verify the library path exists.
		if (!file_exists($path))
		{
			throw new RuntimeException('Library path ' . $path . ' cannot be found.', 500);
		}

		// If the namespace is not yet registered or we have an explicit reset flag then set the path.
		if (!isset(self::$namespaces[$namespace]) || $reset)
		{
			self::$namespaces[$namespace] = array($path);
		}

		// Otherwise we want to simply add the path to the namespace.
		else
		{
			array_unshift(self::$namespaces[$namespace], $path);
		}
	}

	/**
	 * Method to setup the autoloaders for the Joomla Platform.
	 * Since the SPL autoloaders are called in a queue we will add our explicit
	 * class-registration based loader first, then fall back on the autoloader based on conventions.
	 * This will allow people to register a class in a specific location and override platform libraries
	 * as was previously possible.
	 *
	 * @param   integer  $caseStrategy      An option to define the class finding strategy for the namespace loader
	 *                                      depending on the namespace and class path case.
	 *                                      The possible values are :
	 *                                      JLoader::LOWER_CASE : The namespace can be either lower case or camel case and the path lower case.
	 *                                      JLoader::NATURAL_CASE : The namespace case matches the path case.
	 *                                      JLoader::MIXED_CASE : It regroups option 1 and option 2.
	 * @param   boolean  $enableNamespaces  True to enable PHP namespace based class autoloading.
	 * @param   boolean  $enablePrefixes    True to enable prefix based class loading (needed to auto load the Joomla core).
	 * @param   boolean  $enableClasses     True to enable class map based class loading (needed to auto load the Joomla core).
	 *
	 * @return  void
	 *
	 * @since   12.3
	 */
	public static function setup($caseStrategy = self::LOWER_CASE, $enableNamespaces = true, $enablePrefixes = true,
		$enableClasses = true, $enableCompatLayer = true)
	{

		if ($enableCompatLayer)
		{
			self::$nsMap = include JPATH_PLATFORM . '/compat/NamespaceMap.php';

			spl_autoload_register(array(__CLASS__, 'compatLayer'));
		}

		spl_autoload_register(array(__CLASS__, 'loadByPsr0'));
		self::registerNamespace('Joomla', JPATH_PLATFORM);

		if ($enableClasses)
		{
			// Register the class map based autoloader.
			spl_autoload_register(array(__CLASS__, 'load'));
		}

		if ($enablePrefixes)
		{
			// Register the prefix autoloader.
			spl_autoload_register(array(__CLASS__, '_autoload'));
		}

		if ($enableNamespaces)
		{
			switch ($caseStrategy)
			{
				// Register the natural case namespace loader.
				case self::NATURAL_CASE:
					spl_autoload_register(array(__CLASS__, 'loadByNamespaceNaturalCase'));
					break;

				// Register the mixed case namespace loader.
				case self::MIXED_CASE:
					spl_autoload_register(array(__CLASS__, 'loadByNamespaceMixedCase'));
					break;

				// Default to the lower case namespace loader.
				case self::LOWER_CASE:
				default:
					spl_autoload_register(array(__CLASS__, 'loadByNamespaceLowerCase'));
					break;
			}
		}
	}

	/**
	 * Method to alias the old style class name to the fully
	 * qualified class name when the old style class is not found.
	 *
	 * @param   string  $class  The class name to autoload.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   13.1
	 */
	public static function compatLayer($class)
	{
		if (isset(self::$nsMap[$class]))
		{
			// Try to autoload the class, checking for existing old style overrides first.
			if (!self::_autoload($class))
			{
				class_alias(self::$nsMap[$class], $class);
			}

			return true;
		}

		return false;
	}

	/**
	 * Method to autoload classes that are namespaced to the PSR-0 standard.
	 *
	 * @param   string  $class  The fully qualified class name to autoload.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   13.1
	 */
	public static function loadByPsr0($class)
	{
		// Remove the root backslash if present.
		if ($class[0] == '\\')
		{
			$class = substr($class, 1);
		}

		// Find the location of the last NS separator.
		$pos = strrpos($class, '\\');

		// If one is found, we're dealing with a NS'd class.
		if ($pos !== false)
		{
			$classPath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 0, $pos)) . DIRECTORY_SEPARATOR;
			$className = substr($class, $pos + 1);
		}
		// If not, no need to parse path.
		else
		{
			$classPath = null;
			$className = $class;
		}

		$classPath .= str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';

		// Loop through registered namespaces until we find a match.
		foreach (self::$namespaces as $ns => $paths)
		{
			if (strpos($class, $ns) === 0)
			{
				// Loop through paths registered to this namespace until we find a match.
				foreach ($paths as $path)
				{
					$classFilePath = $path . DIRECTORY_SEPARATOR . $classPath; 

					if (file_exists($classFilePath) && !class_exists($class, false))
					{
						return (bool) include_once $classFilePath;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Autoload a class based on name.
	 *
	 * @param   string  $class  The class to be loaded.
	 *
	 * @return  boolean  True if the class was loaded, false otherwise.
	 *
	 * @since   11.3
	 */
	private static function _autoload($class)
	{
		foreach (self::$prefixes as $prefix => $lookup)
		{
			$chr = strlen($prefix) < strlen($class) ? $class[strlen($prefix)] : 0;

			if (strpos($class, $prefix) === 0 && ($chr === strtoupper($chr)))
			{
				return self::_load(substr($class, strlen($prefix)), $lookup);
			}
		}

		return false;
	}

	/**
	 * Load a class based on name and lookup array.
	 *
	 * @param   string  $class   The class to be loaded (without prefix).
	 * @param   array   $lookup  The array of base paths to use for finding the class file.
	 *
	 * @return  boolean  True if the class was loaded, false otherwise.
	 *
	 * @since   12.1
	 */
	private static function _load($class, $lookup)
	{
		// Split the class name into parts separated by camelCase.
		$parts = preg_split('/(?<=[a-z0-9])(?=[A-Z])/x', $class);

		// If there is only one part we want to duplicate that part for generating the path.
		$parts = (count($parts) === 1) ? array($parts[0], $parts[0]) : $parts;

		foreach ($lookup as $base)
		{
			// Generate the path based on the class name parts.
			$path = $base . '/' . implode('/', array_map('strtolower', $parts)) . '.php';

			// Load the file if it exists.
			if (file_exists($path))
			{
				return include $path;
			}
		}

		return false;
	}
}

/**
 * Global application exit.
 *
 * This function provides a single exit point for the platform.
 *
 * @param   mixed  $message  Exit code or string. Defaults to zero.
 *
 * @return  void
 *
 * @codeCoverageIgnore
 * @since   11.1
 */
function jexit($message = 0)
{
	exit($message);
}

/**
 * Intelligent file importer.
 *
 * @param   string  $path  A dot syntax path.
 *
 * @return  boolean  True on success.
 *
 * @since   11.1
 */
function jimport($path)
{
	return JLoader::import($path);
}