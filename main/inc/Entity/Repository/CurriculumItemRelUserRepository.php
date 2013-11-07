<?php

namespace Entity\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\Common\Collections\Criteria;

/**
 * CurriculumItemRelUserRepository
 *
 */
class CurriculumItemRelUserRepository extends EntityRepository
{
    /**
     * Get all users that are registered in the course. No matter the status
     * @param \Entity\CurriculumItem $item
     * @param \Entity\User $user
     * @return bool
     */
    public function isAllowToInsert(\Entity\CurriculumItem $item, \Entity\User $user)
    {
        $max = $item->getMaxRepeat();
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a)')
            ->where('a.itemId = :itemId')
            ->andWhere('a.userId = :userId')
            ->setParameters(
                array(
                    'itemId' => $item->getId(),
                    'userId' => $user->getUserId()
                )
            )
            ->getQuery()
            ->getSingleScalarResult();
        return $count <= $max ? true : false;
    }
}
