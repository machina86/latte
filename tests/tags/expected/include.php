<?php
%A%
final class Template%a% extends Latte\Runtime\Template
{
	public const Source = '%a%.latte';


	public function main(array $ʟ_args): void
	{
%A%
		$this->createTemplate((is_string($ʟ_tmp = 'subdir/include1.latte' . '') ? $ʟ_tmp : throw new InvalidArgumentException(sprintf('Template name must be a string, %s given.', get_debug_type($ʟ_tmp)))), ['localvar' => 10] + $this->params, 'include')->renderToContentType(function ($s, $type) {
			$ʟ_fi = new LR\FilterInfo($type);
			return LR\Filters::convertTo($ʟ_fi, 'html', $this->filters->filterContent('indent', $ʟ_fi, $s));
		}) /* line %d% */;
	}
}
