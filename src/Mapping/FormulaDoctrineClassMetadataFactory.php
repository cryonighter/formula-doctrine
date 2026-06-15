<?php

namespace  Cryonighter\FormulaDoctrine\Mapping;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class FormulaDoctrineClassMetadataFactory extends ChainingClassMetadataFactory
{
    private EntityManagerInterface $em;
    private FormulaMetadataRegistry $registry;

    public function setEntityManager(EntityManagerInterface $em): void
    {
        parent::setEntityManager($em);

        $configuration = $em->getConfiguration();

        $this->registry = $configuration->getDefaultQueryHint(FormulaSqlWalker::HINT_REGISTRY);

        $this->em = $em;
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        $classMetadata = parent::getMetadataFor($className);

        $this->registry->createForClass($className, $classMetadata->getTableName(), $this->em);

        return $classMetadata;
    }
}
