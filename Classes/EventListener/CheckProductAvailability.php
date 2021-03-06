<?php
declare(strict_types=1);
namespace Extcode\CartProducts\EventListener;

/*
 * This file is part of the package extcode/cart-products.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Event\CheckProductAvailabilityEvent;
use Extcode\CartProducts\Domain\Repository\Product\ProductRepository;
use Extcode\CartProducts\Utility\ProductUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class CheckProductAvailability
{
    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var ProductUtility
     */
    protected $productUtility;

    public function __construct(
        ProductRepository $productRepository,
        ProductUtility $productUtility
    ) {
        $this->productRepository = $productRepository;
        $this->productUtility = $productUtility;
    }

    public function __invoke(CheckProductAvailabilityEvent $event): void
    {
        $cart = $event->getCart();
        $cartProduct = $event->getProduct();
        $quantity = $event->getQuantity();

        $mode = $event->getMode();

        if ($cartProduct->getProductType() !== 'CartProducts') {
            return;
        }

        $querySettings = $this->productRepository->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->productRepository->setDefaultQuerySettings($querySettings);

        $product = $this->productRepository->findByIdentifier($cartProduct->getProductId());

        if (!$product->isHandleStock()) {
            return;
        }

        if (!$product->isHandleStockInVariants()) {
            if (is_array($quantity)) {
                $compareQuantity = array_sum($quantity);
            } else {
                $compareQuantity = $quantity;
            }
            if (($mode === 'add') && $cart->getProduct($cartProduct->getId())) {
                $compareQuantity += $cart->getProduct($cartProduct->getId())->getQuantity();
            }

            if ($compareQuantity > $product->getStock()) {
                $this->falseAvailability($event);
            }

            return;
        }

        foreach ($cartProduct->getBeVariants() as $beVariant) {
            if (($mode === 'add') && $cart->getProduct($cartProduct->getId())) {
                if ($cart->getProduct($cartProduct->getId())->getBeVariant($beVariant->getId())) {
                    $compareQuantity += (int)$cart->getProduct($cartProduct->getId())->getBeVariant($beVariant->getId())->getQuantity();
                }
            }

            if ($compareQuantity > $beVariant->getStock()) {
                $this->falseAvailability($event);
            }
        }
    }

    /**
     * @param CheckProductAvailabilityEvent $event
     */
    protected function falseAvailability(CheckProductAvailabilityEvent $event): void
    {
        $event->setAvailable(false);
        $event->addMessage(
            $flashMessage = GeneralUtility::makeInstance(
                FlashMessage::class,
                LocalizationUtility::translate(
                    'tx_cart.error.stock_handling.update',
                    'cart'
                ),
                '',
                AbstractMessage::ERROR
            )
        );
    }
}
