<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte;

use Latte\Compiler\Nodes\TemplateNode;
use function array_map, array_merge, class_exists, extension_loaded, filemtime, get_debug_type, get_object_vars, is_array, md5, preg_match, serialize, strpos, substr;
use const PHP_VERSION_ID;


/**
 * Templating engine Latte.
 */
class Engine
{
	public const Version = '3.0.22';
	public const VersionId = 30022;

	/** @deprecated use Engine::Version */
	public const
		VERSION = self::Version,
		VERSION_ID = self::VersionId;

	/** @deprecated use ContentType::* */
	public const
		CONTENT_HTML = ContentType::Html,
		CONTENT_XML = ContentType::Xml,
		CONTENT_JS = ContentType::JavaScript,
		CONTENT_CSS = ContentType::Css,
		CONTENT_ICAL = ContentType::ICal,
		CONTENT_TEXT = ContentType::Text;

	private ?Loader $loader = null;
	private Runtime\FilterExecutor $filters;
	private Runtime\FunctionExecutor $functions;
	private \stdClass $providers;

	/** @var Extension[] */
	private array $extensions = [];
	private string $contentType = ContentType::Html;
	private Cache $cache;
	private bool $strictTypes = false;
	private bool $strictParsing = false;
	private ?Policy $policy = null;
	private bool $sandboxed = false;
	private ?string $phpBinary = null;
	private ?string $environmentHash;
	private ?string $locale = null;
	private ?string $defaultSyntax = null;

	public function __construct()
	{
		$this->cache = new Cache;
		$this->filters = new Runtime\FilterExecutor;
		$this->functions = new Runtime\FunctionExecutor;
		$this->providers = new \stdClass;
		$this->addExtension(new Essential\CoreExtension);
		$this->addExtension(new Sandbox\SandboxExtension);
	}


	/**
	 * Renders template to output.
	 * @param  object|mixed[]  $params
	 */
	public function render(string $name, object|array $params = [], ?string $block = null): void
	{
		$template = $this->createTemplate($name, $this->processParams($params));
		$template->global->coreCaptured = false;
		$template->render($block);
	}


	/**
	 * Renders template to string.
	 * @param  object|mixed[]  $params
	 */
	public function renderToString(string $name, object|array $params = [], ?string $block = null): string
	{
		$template = $this->createTemplate($name, $this->processParams($params));
		$template->global->coreCaptured = true;
		return $template->capture(fn() => $template->render($block));
	}


	/**
	 * Creates template object.
	 * @param  mixed[]  $params
	 */
	public function createTemplate(string $name, array $params = [], bool $clearCache = true): Runtime\Template
	{
		$this->environmentHash = $clearCache ? null : $this->environmentHash;
		$class = $this->loadTemplate($name);
		$this->providers->fn = $this->functions;
		return new $class(
			$this,
			$params,
			$this->filters,
			$this->providers,
			$name,
		);
	}


	/**
	 * Compiles template to PHP code.
	 */
	public function compile(string $name): string
	{
		if ($this->sandboxed && !$this->policy) {
			throw new \LogicException('In sandboxed mode you need to set a security policy.');
		}

		$template = $this->getLoader()->getContent($name);

		try {
			$node = $this->parse($template);
			$this->applyPasses($node);
			$compiled = $this->generate($node, $name);

		} catch (\Throwable $e) {
			if (!$e instanceof CompileException && !$e instanceof SecurityViolationException) {
				$e = new CompileException("Thrown exception '{$e->getMessage()}'", previous: $e);
			}

			throw $e->setSource($template, $name);
		}

		if ($this->phpBinary) {
			Compiler\PhpHelpers::checkCode($this->phpBinary, $compiled, "(compiled $name)");
		}

		return $compiled;
	}


	/**
	 * Parses template to AST node.
	 */
	public function parse(string $template): TemplateNode
	{
		$parser = new Compiler\TemplateParser;
		if ($this->defaultSyntax) {
			$parser->setSyntax($this->defaultSyntax);
		}
		$parser->strict = $this->strictParsing;

		foreach ($this->extensions as $extension) {
			$extension->beforeCompile($this);
			$parser->addTags($extension->getTags());
		}

		return $parser
			->setContentType($this->contentType)
			->setPolicy($this->getPolicy(effective: true))
			->parse($template);
	}


	/**
	 * Calls node visitors.
	 */
	public function applyPasses(TemplateNode &$node): void
	{
		$passes = [];
		foreach ($this->extensions as $extension) {
			$passes = array_merge($passes, $extension->getPasses());
		}

		$passes = Helpers::sortBeforeAfter($passes);
		foreach ($passes as $pass) {
			$pass = $pass instanceof \stdClass ? $pass->subject : $pass;
			($pass)($node);
		}
	}


	/**
	 * Generates compiled PHP code.
	 */
	public function generate(TemplateNode $node, string $name): string
	{
		$generator = new Compiler\TemplateGenerator;
		return $generator->generate(
			$node,
			$this->getTemplateClass($name),
			$name,
			$this->strictTypes,
		);
	}


	/**
	 * Compiles template to cache.
	 * @throws \LogicException
	 */
	public function warmupCache(string $name): void
	{
		if (!$this->cache->directory) {
			throw new \LogicException('Path to temporary directory is not set.');
		}

		$this->loadTemplate($name);
	}


	private function loadTemplate(string $name): string
	{
		$class = $this->getTemplateClass($name);
		if (class_exists($class, false)) {
			// nothing
		} elseif ($this->cache->directory) {
			$this->cache->loadOrCreate($this, $name);
		} else {
			$compiled = $this->compile($name);
			if (@eval(substr($compiled, 5)) === false) { // @ is escalated to exception, substr removes <?php
				throw (new CompileException('Error in template: ' . error_get_last()['message']))
					->setSource($compiled, "$name (compiled)");
			}
		}
		return $class;
	}


	public function getCacheFile(string $name): string
	{
		return $this->cache->generateFileName($name, $this->generateTemplateHash($name));
	}


	public function getTemplateClass(string $name): string
	{
		return 'Template_' . $this->generateTemplateHash($name);
	}


	private function generateTemplateHash(string $name): string
	{
		$this->environmentHash ??= md5(serialize($this->getCacheKey()));
		$hash = $this->environmentHash . $this->getLoader()->getUniqueId($name);
		return substr(md5($hash), 0, 10);
	}


	/**
	 * Values that affect the results of compilation and the name of the cache file.
	 */
	protected function getCacheKey(): array
	{
		return [
			$this->contentType,
			array_map(
				fn($extension) => [
					get_debug_type($extension),
					$extension->getCacheKey($this),
					filemtime((new \ReflectionObject($extension))->getFileName()),
				],
				$this->extensions,
			),
		];
	}


	/**
	 * Registers run-time filter.
	 */
	public function addFilter(string $name, callable $callback): static
	{
		if (!preg_match('#^[a-z]\w*$#iD', $name)) {
			throw new \LogicException("Invalid filter name '$name'.");
		}

		$this->filters->add($name, $callback);
		return $this;
	}


	/**
	 * Registers filter loader.
	 */
	public function addFilterLoader(callable $loader): static
	{
		$this->filters->add(null, $loader);
		return $this;
	}


	/**
	 * Returns all run-time filters.
	 * @return callable[]
	 */
	public function getFilters(): array
	{
		return $this->filters->getAll();
	}


	/**
	 * Call a run-time filter.
	 * @param  mixed[]  $args
	 */
	public function invokeFilter(string $name, array $args): mixed
	{
		return ($this->filters->$name)(...$args);
	}


	/**
	 * Adds new extension.
	 */
	public function addExtension(Extension $extension): static
	{
		$this->extensions[] = $extension;
		foreach ($extension->getFilters() as $name => $value) {
			$this->filters->add($name, $value);
		}

		foreach ($extension->getFunctions() as $name => $value) {
			$this->functions->add($name, $value);
		}

		foreach ($extension->getProviders() as $name => $value) {
			$this->providers->$name = $value;
		}
		return $this;
	}


	/** @return Extension[] */
	public function getExtensions(): array
	{
		return $this->extensions;
	}


	/**
	 * Registers run-time function.
	 */
	public function addFunction(string $name, callable $callback): static
	{
		if (!preg_match('#^[a-z]\w*$#iD', $name)) {
			throw new \LogicException("Invalid function name '$name'.");
		}

		$this->functions->add($name, $callback);
		return $this;
	}


	/**
	 * Call a run-time function.
	 * @param  mixed[]  $args
	 */
	public function invokeFunction(string $name, array $args): mixed
	{
		return ($this->functions->$name)(null, ...$args);
	}


	/**
	 * @return callable[]
	 */
	public function getFunctions(): array
	{
		return $this->functions->getAll();
	}


	/**
	 * Adds new provider.
	 */
	public function addProvider(string $name, mixed $provider): static
	{
		if (!preg_match('#^[a-z]\w*$#iD', $name)) {
			throw new \LogicException("Invalid provider name '$name'.");
		}

		$this->providers->$name = $provider;
		return $this;
	}


	/**
	 * Returns all providers.
	 * @return mixed[]
	 */
	public function getProviders(): array
	{
		return (array) $this->providers;
	}


	public function setPolicy(?Policy $policy): static
	{
		$this->policy = $policy;
		return $this;
	}


	public function getPolicy(bool $effective = false): ?Policy
	{
		return !$effective || $this->sandboxed
			? $this->policy
			: null;
	}


	public function setExceptionHandler(callable $handler): static
	{
		$this->providers->coreExceptionHandler = $handler;
		return $this;
	}


	public function setSandboxMode(bool $state = true): static
	{
		$this->sandboxed = $state;
		return $this;
	}


	public function setContentType(string $type): static
	{
		$this->contentType = $type;
		return $this;
	}


	/**
	 * Sets path to temporary directory.
	 */
	public function setTempDirectory(?string $path): static
	{
		$this->cache->directory = $path;
		return $this;
	}


	/**
	 * Sets auto-refresh mode.
	 */
	public function setAutoRefresh(bool $state = true): static
	{
		$this->cache->autoRefresh = $state;
		return $this;
	}


	/**
	 * Enables declare(strict_types=1) in templates.
	 */
	public function setStrictTypes(bool $state = true): static
	{
		$this->strictTypes = $state;
		return $this;
	}


	public function setStrictParsing(bool $state = true): static
	{
		$this->strictParsing = $state;
		return $this;
	}


	public function isStrictParsing(): bool
	{
		return $this->strictParsing;
	}


	/**
	 * Sets the locale. It uses the same identifiers as the PHP intl extension.
	 */
	public function setLocale(?string $locale): static
	{
		if ($locale && !extension_loaded('intl')) {
			throw new RuntimeException("Setting a locale requires the 'intl' extension to be installed.");
		}
		$this->locale = $locale;
		return $this;
	}


	public function getLocale(): ?string
	{
		return $this->locale;
	}
	

	/**
	 * Sets default tag syntax
	 */
	public function setDefaultSyntax(?string $defaultSyntax): static
	{
		$this->defaultSyntax = $defaultSyntax;
		return $this;
	}

	public function setLoader(Loader $loader): static
	{
		$this->loader = $loader;
		return $this;
	}


	public function getLoader(): Loader
	{
		return $this->loader ??= new Loaders\FileLoader;
	}


	public function enablePhpLinter(?string $phpBinary): static
	{
		$this->phpBinary = $phpBinary;
		return $this;
	}


	/**
	 * @param  object|mixed[]  $params
	 * @return mixed[]
	 */
	private function processParams(object|array $params): array
	{
		if (is_array($params)) {
			return $params;
		}

		$rc = new \ReflectionClass($params);
		$methods = $rc->getMethods(\ReflectionMethod::IS_PUBLIC);
		foreach ($methods as $method) {
			if ($method->getAttributes(Attributes\TemplateFilter::class)) {
				$this->addFilter($method->name, [$params, $method->name]);
			}

			if ($method->getAttributes(Attributes\TemplateFunction::class)) {
				$this->addFunction($method->name, [$params, $method->name]);
			}

			if (strpos((string) $method->getDocComment(), '@filter')) {
				trigger_error('Annotation @filter is deprecated, use attribute #[Latte\Attributes\TemplateFilter]');
				$this->addFilter($method->name, [$params, $method->name]);
			}

			if (strpos((string) $method->getDocComment(), '@function')) {
				trigger_error('Annotation @function is deprecated, use attribute #[Latte\Attributes\TemplateFunction]');
				$this->addFunction($method->name, [$params, $method->name]);
			}
		}

		$res = get_object_vars($params);
		if (PHP_VERSION_ID >= 80400) {
			foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
				if ($property->isVirtual() && $property->hasHook(\PropertyHookType::Get)) {
					$name = $property->getName();
					$res[$name] = $params->$name;
				}
			}
		}

		return $res;
	}


	public function __get(string $name)
	{
		if ($name === 'onCompile') {
			$trace = debug_backtrace(0)[0];
			$loc = isset($trace['file'], $trace['line'])
				? ' (in ' . $trace['file'] . ' on ' . $trace['line'] . ')'
				: '';
			throw new \LogicException('You use Latte 3 together with the code designed for Latte 2' . $loc);
		}
	}
}
