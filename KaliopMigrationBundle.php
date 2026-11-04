<?php

namespace Kaliop\IbexaMigrationBundle;

use Kaliop\IbexaMigrationBundle\DependencyInjection\CompilerPass\TaggedServicesCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class KaliopMigrationBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TaggedServicesCompilerPass());
    }
}
