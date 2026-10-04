<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared admin controller wiring: request params/post, recorded flash messages and redirect target.
 */
abstract class ControllerTestCase extends TestCase
{
    protected array $redirect = [];
    protected array $messages = ['success' => [], 'error' => [], 'notice' => []];
    protected ?Redirect $redirectResult = null;

    protected function context(array $post = [], array $params = [], $result = null): Context
    {
        $this->redirect = [];
        $this->messages = ['success' => [], 'error' => [], 'notice' => []];

        $request = $this->createStub(Http::class);
        $request->method('getPost')->willReturnCallback(
            static fn($key = null, $default = null) => $post[$key] ?? $default
        );
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function ($path, $args = []) use (&$redirect) {
                $this->redirect = ['path' => $path, 'params' => $args];
                return $redirect;
            }
        );
        $this->redirectResult = $redirect;

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($result ?? $redirect);

        $messageManager = $this->createStub(ManagerInterface::class);
        foreach (array_keys($this->messages) as $type) {
            $messageManager->method('add' . ucfirst($type) . 'Message')->willReturnCallback(
                function ($message) use ($type, &$messageManager) {
                    $this->messages[$type][] = (string)$message;
                    return $messageManager;
                }
            );
        }

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);
        $context->method('getMessageManager')->willReturn($messageManager);
        return $context;
    }
}
