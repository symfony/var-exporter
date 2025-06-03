<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter\Internal;

use Symfony\Component\Serializer\Attribute\Ignore;
/**
 * @internal
 * @deprecated since Symfony 7.3
 */
trait LazyObjectTrait
{
    #[Ignore]
    private readonly LazyObjectState $lazyObjectState;
}
