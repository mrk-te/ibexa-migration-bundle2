<?php

namespace Kaliop\IbexaMigrationBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class TaggedServicesCompilerPass implements CompilerPassInterface
{
    /**
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has('kaliop_migration_bundle.migration_service')) {
            $migrationService = $container->findDefinition('kaliop_migration_bundle.migration_service');

            $DefinitionParsers = $container->findTaggedServiceIds('kaliop_migration_bundle.definition_parser');
            foreach ($DefinitionParsers as $id => $tags) {
                $migrationService->addMethodCall('addDefinitionParser', array(
                    new Reference($id)
                ));
            }

            $executors = $container->findTaggedServiceIds('kaliop_migration_bundle.executor');
            foreach ($executors as $id => $tags) {
                $migrationService->addMethodCall('addExecutor', array(
                    new Reference($id)
                ));
            }
        }

        if ($container->has('kaliop_migration_bundle.complex_field_manager')) {
            $migrationService = $container->findDefinition('kaliop_migration_bundle.complex_field_manager');

            $DefinitionParsers = $container->findTaggedServiceIds('kaliop_migration_bundle.complex_field');

            // allow for prioritization of tagged services
            $handlers = array();
            $priorities = array();

            foreach ($DefinitionParsers as $id => $tags) {
                foreach ($tags as $attributes) {
                    $priorities[] = isset($attributes['priority']) ? $attributes['priority'] : 0;
                    $handlers[] = array(
                        new Reference($id),
                        $attributes['fieldtype'],
                        isset($attributes['contenttype']) ? $attributes['contenttype'] : null
                    );
                }
            }

            asort($priorities);

            foreach ($priorities as $id => $priority) {
                $migrationService->addMethodCall('addFieldHandler', $handlers[$id]);
            }
        }

        if ($container->has('kaliop_migration_bundle.reference_resolver.customreference.flexible')) {
            $customReferenceResolver = $container->findDefinition('kaliop_migration_bundle.reference_resolver.customreference.flexible');
            $extraResolvers = $container->findTaggedServiceIds('kaliop_migration_bundle.reference_resolver.customreference');

            foreach ($extraResolvers as $id => $tags) {
                $customReferenceResolver->addMethodCall('addResolver', array(
                    new Reference($id)
                ));
            }
        }

        if ($container->has('kaliop_migration_bundle.context_handler')) {
            $contextHandlerService = $container->findDefinition('kaliop_migration_bundle.context_handler');
            $ContextProviders = $container->findTaggedServiceIds('kaliop_migration_bundle.context_provider');

            foreach ($ContextProviders as $id => $tags) {
                foreach ($tags as $attributes) {
                    $contextHandlerService->addMethodCall('addProvider', array(new Reference($id), $attributes['label']));
                }
            }
        }
    }
}
