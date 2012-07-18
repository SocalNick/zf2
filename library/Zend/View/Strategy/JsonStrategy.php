<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_View
 */

namespace Zend\View\Strategy;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Response as HttpResponse;
use Zend\View\Model;
use Zend\View\Renderer\JsonRenderer;
use Zend\View\ViewEvent;

/**
 * @category   Zend
 * @package    Zend_View
 * @subpackage Strategy
 */
class JsonStrategy implements StrategyInterface, ListenerAggregateInterface
{
    /**
     * @var \Zend\Stdlib\CallbackHandler[]
     */
    protected $listeners = array();

    /**
     * @var JsonRenderer
     */
    protected $renderer;

    /**
     * @var double
     */
    protected $matchPriority = 0;

    /**
     * Constructor
     *
     * @param  JsonRenderer $renderer
     * @return void
     */
    public function __construct(JsonRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    /**
     * Retrieve the composed renderer
     *
     * @return JsonRenderer
     */
    public function getRenderer()
    {
        return $this->renderer;
    }

    /**
     * Attach the aggregate to the specified event manager
     *
     * @param  EventManagerInterface $events
     * @param  int $priority
     * @return void
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(ViewEvent::EVENT_RENDERER, array($this, 'resolveStrategyPriority'), $priority);
        $this->listeners[] = $events->attach(ViewEvent::EVENT_RESPONSE, array($this, 'injectResponse'), $priority);
    }

    /**
     * Detach aggregate listeners from the specified event manager
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    /**
     * The match priority, normally a double between 0 and 1
     *
     * @return double
     */
    public function getMatchPriority()
    {
        return $this->matchPriority;
    }

    /**
     * Detect if we should use the JsonRenderer based on model type and/or
     * Accept header
     *
     * @param  ViewEvent $e
     * @return null|JsonRenderer
     */
    public function resolveStrategyPriority(ViewEvent $e)
    {
        $model = $e->getModel();

        if ($model instanceof Model\JsonModel) {
            // JsonModel found
            $this->matchPriority = 1;
            return $this;
        }

        $request = $e->getRequest();
        if (!$request instanceof HttpRequest) {
            // Not an HTTP request; cannot autodetermine
            return;
        }

        $headers = $request->getHeaders();
        if (!$headers->has('accept')) {
            return;
        }


        $accept  = $headers->get('Accept');
        if (($match = $accept->match('application/json, application/javascript')) == false) {
            return;
        }
        $this->matchPriority = $match->getPriority();

        if ($match->getFormat() == 'json') {
            // application/json Accept header found
            return $this;
        }

        // application/javascript Accept header found
        if (false != ($callback = $request->getQuery()->get('callback'))) {
            $this->renderer->setJsonpCallback($callback);
        }

        return $this;
    }

    /**
     * Inject the response with the JSON payload and appropriate Content-Type header
     *
     * @param  ViewEvent $e
     * @return void
     */
    public function injectResponse(ViewEvent $e)
    {
        $renderer = $e->getRenderer();
        if ($renderer !== $this->renderer) {
            // Discovered renderer is not ours; do nothing
            return;
        }

        $result   = $e->getResult();
        if (!is_string($result)) {
            // We don't have a string, and thus, no JSON
            return;
        }

        // Populate response
        $response = $e->getResponse();
        $response->setContent($result);
        $headers = $response->getHeaders();
        if ($this->renderer->hasJsonpCallback()) {
            $headers->addHeaderLine('content-type', 'application/javascript');
        } else {
            $headers->addHeaderLine('content-type', 'application/json');
        }
    }
}
