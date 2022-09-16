<?php

declare(strict_types=1);

namespace Garagist\Plaintext\Eel;

use Neos\Flow\Annotations as Flow;
use Garagist\Plaintext\Service\Html2TextService;
use Neos\Eel\ProtectedContextAwareInterface;

class PlaintextHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var Html2TextService
     */
    protected $html2TextService;

    /**
     * Create a plaintext version of the html
     *
     * @param string $html
     * @param array|null $options
     * @param string|null $url for log output
     * @return string
     */
    public function convert(string $html, ?array $options = null, ?string $url = null): string
    {
        return $this->html2TextService->convert($html, $options, $url);
    }


    /**
     * @param string $methodName
     * @return bool
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
