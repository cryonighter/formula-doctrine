<?php

namespace  Cryonighter\FormulaDoctrine\Mapping;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class FormulaDoctrineClassMetadataFactory extends ChainingClassMetadataFactory
{
    private FormulaMetadataRegistry $registry;

    public function setEntityManager(EntityManagerInterface $em): void
    {
        parent::setEntityManager($em);

        $configuration = $em->getConfiguration();

        $this->registry = $configuration->getDefaultQueryHint(FormulaSqlWalker::HINT_REGISTRY);
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        $classMetadata = parent::getMetadataFor($className);

        $this->registry->getForClass($className);
        $this->registry->setTableNameForClass($className, $classMetadata->getTableName());

        return $classMetadata;
    }
}
