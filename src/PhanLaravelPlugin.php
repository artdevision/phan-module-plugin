<?php
declare(strict_types=1);

namespace Artdevision\PhanLaravelPlugin;

use ast\Node;
use Phan\AST\ContextNode;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\NamespaceMapEntry;
use Phan\PluginV3;
use PhpParser\Node\Name\FullyQualified;

class PhanLaravelPlugin extends PluginV3 implements PluginV3\PostAnalyzeNodeCapability
{
    public static function getPostAnalyzeNodeVisitorClassName(): string
    {
        return PhanLaravelPluginVisitor::class;
    }
}

class PhanLaravelPluginVisitor extends PluginV3\PluginAwarePostAnalysisVisitor
{
    private const MODULES = 'Modules';
    private const FACADES = 'Facades';
    private const ISSUE_TYPE = 'ModuleСohesion';
    private const DTO = 'DTO';

    public function visitUse(Node $node): void
    {
        foreach ($node->children as $child) {
            if(isset($child->children['name']) && $this->isModule($child->children['name'])) {
                if(!$this->isAllowed($child->children['name'] ?? '')) {
                    $this->emit(
                        self::ISSUE_TYPE,
                        'Module:{STRING_LITERAL} used {CLASS} from other Module:{STRING_LITERAL}',
                        [
                            $this->getModuleName($this->context->getNamespace()),
                            $child->children['name'],
                            $this->getModuleName($child->children['name'])
                        ]
                    );
                }
            }
        }
    }

    public function visitParam(Node $node): void
    {
        if ($this->isModule($this->context->getNamespace())) {
            $nodeNamespace = $this->getNamespace($node) ?? $node->children['type']?->children['name'] ?? null;
            if ($nodeNamespace !== null) {
                if ($nodeNamespace && !$this->isAllowed($nodeNamespace)) {
                    $this->emit(
                        self::ISSUE_TYPE,
                        '{CLASS}::class Module:{STRING_LITERAL} used Param: {VARIABLE} with type from other Module:{STRING_LITERAL}',
                        [
                            (string) $this->context->getClassFQSEN(),
                            $this->getModuleName($this->context->getNamespace()),
                            $node->children['name'],
                            $this->getModuleName($nodeNamespace),
                        ]
                    );
                }
            }
        }
    }

    public function visitNew(Node $node)
    {
        if ($this->isModule($this->context->getNamespace())) {
            $nodeNamespace = $this->getNamespace($node) ?? $node->children['class']?->children['name'] ?? null;
            if($nodeNamespace !== null
                && !$this->isAllowed($nodeNamespace)
            ) {
                $this->emit(
                    self::ISSUE_TYPE,
                    '{CLASS}::class Module:{STRING_LITERAL} try to make {VARIABLE} object from other Module:{STRING_LITERAL}',
                    [
                        (string) $this->context->getClassFQSEN(),
                        $this->getModuleName($this->context->getNamespace()),
                        $node->children['class']?->children['name'],
                        $this->getModuleName($nodeNamespace),
                    ]
                );
            }
        }
    }

    public function visitAssign(Node $node)
    {
        if ($this->isModule($this->context->getNamespace())) {
            $nodeNamespace = $this->getNamespace($node)
                ?? $node?->children['expr']?->children['class']?->children['name']
                ?? null;
            $qualifiedName = (new ContextNode($this->code_base, $this->context, $node))->getQualifiedName();

            if(
                $qualifiedName
                && !$this->isAllowed($qualifiedName)
                && isset($node->children['var'])
            ) {
                $this->emit(
                    self::ISSUE_TYPE,
                    '{CLASS}::class Module:{STRING_LITERAL} try to assign ${VARIABLE} value type from other Module:{STRING_LITERAL}',
                    [
                        (string) $this->context->getClassFQSEN(),
                        $this->getModuleName($this->context->getNamespace()),
                        $node->children['var']->children['name'],
                        $this->getModuleName($qualifiedName),
                    ]
                );
            }
            if(isset($node->children['expr']->children['method']) && $nodeNamespace !== null) {
                $methodFQSEN = FullyQualifiedMethodName::fromFullyQualifiedString("$nodeNamespace::{$node->children['expr']->children['method']}");
                if($this->code_base->hasMethodWithFQSEN($methodFQSEN)) {
                    $method = $this->code_base->getMethodByFQSEN($methodFQSEN);
                    $return = $method->hasReturn() ? $method->getRealReturnType() : null;
                    foreach ($return->getTypeSet() as $type) {
                        $typeNamespace = $type->getNamespace() .'\\'. $type->getName();
                        if (
                            $type->isObject()
                            && !$this->isAllowed($typeNamespace)
                            && isset($node->children['var'])
                        ) {
                            $this->emit(
                                self::ISSUE_TYPE,
                                '{CLASS}::class Module:{STRING_LITERAL} try to assign ${VARIABLE} value type from other Module:{STRING_LITERAL}',
                                [
                                    (string) $this->context->getClassFQSEN(),
                                    $this->getModuleName($this->context->getNamespace()),
                                    $node->children['var']->children['name'],
                                    $this->getModuleName($typeNamespace),
                                ]
                            );
                        }
                    }
                }
            }
            if($nodeNamespace !== null
                && !$this->isAllowed($nodeNamespace)
                && isset($node->children['var'])) {
                $this->emit(
                    self::ISSUE_TYPE,
                    '{CLASS}::class Module:{STRING_LITERAL} try to assign ${VARIABLE} value type from other Module:{STRING_LITERAL}',
                    [
                        (string) $this->context->getClassFQSEN(),
                        $this->getModuleName($this->context->getNamespace()),
                        $node->children['var']->children['name'],
                        $this->getModuleName($nodeNamespace),
                    ]
                );
            }
        }
    }

    public function visitReturn(Node $node)
    {
        if ($this->isModule($this->context->getNamespace())) {
            $nodeNamespace = $this->getNamespace($node)
                ?? $node?->children['expr']?->children['class']?->children['name']
                ?? null;
            if($nodeNamespace !== null
                && !$this->isAllowed($nodeNamespace)
                && isset($node->children['expr'])) {
                $this->emit(
                    self::ISSUE_TYPE,
                    '{CLASS}::class Module:{STRING_LITERAL} try to return type:{CLASS} from other Module:{STRING_LITERAL}',
                    [
                        (string) $this->context->getClassFQSEN(),
                        $this->getModuleName($this->context->getNamespace()),
                        $nodeNamespace,
                        $this->getModuleName($nodeNamespace),
                    ]
                );
            }
        }
    }

    protected function getNamespace(Node $node): ?string
    {
        $namespaceMap = $this->context->getNamespaceMap();

        $entries = match ($node->kind) {
            \ast\AST_ASSIGN => array_filter($namespaceMap[1] ?? [], function (NamespaceMapEntry $item, $key) use ($node) {
                return (
                        isset($node->children['expr'])
                        && isset($node->children['expr']->children['class'])
                        &&  $node->children['expr']?->children['class']?->children['name'] === $item->original_name
                    ) || (
                        isset($node->children['expr'])
                        && isset($node->children['expr']->children['expr'])
                        && isset($node->children['expr']->children['expr']->children['class'])
                        && $node?->children['expr']->children['expr']->children['class']?->children['name'] === $item->original_name
                    );
            }, ARRAY_FILTER_USE_BOTH),
            \ast\AST_RETURN => array_filter($namespaceMap[1] ?? [], function (NamespaceMapEntry $item, $key) use ($node) {
                return isset($node->children['expr'])
                && isset($node->children['expr']->children['class'])
                && $node->children['expr']?->children['class']?->children['name'] === $item->original_name;
            }, ARRAY_FILTER_USE_BOTH),
            \ast\AST_PARAM => array_filter($namespaceMap[1] ?? [], function (NamespaceMapEntry $item, $key) use ($node) {
                return isset($node->children['type']?->children['name'])
                    && $node->children['type']?->children['name'] === $item?->original_name;
            }, ARRAY_FILTER_USE_BOTH),
            \ast\AST_NEW => array_filter($namespaceMap[1] ?? [], function(NamespaceMapEntry $item, $key) use($node) {
                return isset($node->children['class']?->children['name'])
                    && $node->children['class']?->children['name'] === $item?->original_name;
            }, ARRAY_FILTER_USE_BOTH),
            default => []
        };

        return $entries !== [] ? (string) $entries[array_key_first($entries)]?->fqsen : null;
    }

    protected function getModuleName(?string $namespace): ?string
    {
        if ($namespace === null) {
            return null;
        }
        $parts = explode('\\', $namespace);
        $i = array_search(self::MODULES, $parts);
        return $i !== false ? ($parts[++$i] ?? null) : null;
    }

    protected function isModule(string $namespace): bool
    {
        return str_contains($namespace, self::MODULES);
    }

    protected function isAllowed(string $namespace): bool
    {
        return str_contains($namespace, self::FACADES)
            || str_contains($namespace, self::DTO)
            || $this->getModuleName($namespace) === null
            || ltrim($this->getModuleName($namespace) ?? '', '\\') === $this->getModuleName($this->context->getNamespace());
    }
}

return new PhanLaravelPlugin();
