<?php

/*
 * This file is part of the Symfony framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\Bundle\AsseticBundle\Twig;

use Assetic\Extension\Twig\AsseticFilterFunction;
use Symfony\Bundle\AsseticBundle\Exception\InvalidBundleException;
use Symfony\Bundle\FrameworkBundle\Templating\TemplateReference;
use Symfony\Component\Templating\TemplateNameParserInterface;
use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\AbstractNodeVisitor;
use Twig\Template;

/**
 * Assetic node visitor.
 *
 * @author Kris Wallsmith <kris@symfony.com>
 */
class AsseticNodeVisitor extends AbstractNodeVisitor
{
    private $templateNameParser;
    private $enabledBundles;

    public function __construct(TemplateNameParserInterface $templateNameParser, array $enabledBundles)
    {
        $this->templateNameParser = $templateNameParser;
        $this->enabledBundles = $enabledBundles;
    }

    protected function doEnterNode(Node $node, Environment $env)
    {
        return $node;
    }

    protected function doLeaveNode(Node $node, Environment $env)
    {
        if (!$formula = $this->checkNode($node, $env, $name)) {
            return $node;
        }

        // check the bundle
        $templateRef = $this->templateNameParser->parse($env->getParser()->getStream()->getFilename());
        $bundle = $templateRef instanceof TemplateReference ? $templateRef->get('bundle') : null;
        if ($bundle && !in_array($bundle, $this->enabledBundles)) {
            throw new InvalidBundleException($bundle, "the $name() function", $templateRef->getLogicalName(), $this->enabledBundles);
        }

        list($input, $filters, $options) = $formula;
        $line = $node->getLine();

        // check context and call either asset() or path()
        return new ConditionalExpression(
            new GetAttrExpression(
                new NameExpression('assetic', $line),
                new ConstantExpression('use_controller', $line),
                new ArrayExpression(array(), 0),
                Template::ARRAY_CALL,
                $line
            ),
            new FunctionExpression(
                'path',
                new Node(array(
                    new ConstantExpression('_assetic_'.$options['name'], $line),
                )),
                $line
            ),
            new FunctionExpression(
                'asset',
                new Node(array($node, new ConstantExpression(isset($options['package']) ? $options['package'] : null, $line))),
                $line
            ),
            $line
        );
    }

    /**
     * Extracts formulae from filter function nodes.
     *
     * @return array|null The formula
     */
    private function checkNode(Node $node, Environment $env, &$name = null)
    {
        if ($node instanceof FunctionExpression) {
            $name = $node->getAttribute('name');
            if ($env->getFunction($name) instanceof AsseticFilterFunction) {
                $arguments = array();
                foreach ($node->getNode('arguments') as $argument) {
                    $arguments[] = eval('return '.$env->compile($argument).';');
                }

                $invoker = $env->getExtension('assetic')->getFilterInvoker($name);
                $factory = $invoker->getFactory();

                $inputs = isset($arguments[0]) ? (array) $arguments[0] : array();
                $filters = $invoker->getFilters();
                $options = array_replace($invoker->getOptions(), isset($arguments[1]) ? $arguments[1] : array());

                if (!isset($options['name'])) {
                    $options['name'] = $factory->generateAssetName($inputs, $filters);
                }

                return array($inputs, $filters, $options);
            }
        }
    }

    public function getPriority()
    {
        return 0;
    }
}
