<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Fixture;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\DataObject;

/**
 * Auth session whose current admin user is fixed at construction time.
 */
class AuthSessionDouble extends Session
{
    private ?DataObject $fixedUser;

    public function __construct(?int $userId)
    {
        $this->fixedUser = $userId === null ? null : new DataObject(['id' => $userId]);
    }

    /**
     * @return DataObject|null
     */
    public function getUser()
    {
        return $this->fixedUser;
    }
}
