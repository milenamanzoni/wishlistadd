<?php
/**
 * @package Chupaprecios_ProductSearch
 */
declare(strict_types=1);

namespace Chupaprecios\ProductSearch\Controller\Search;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Wishlist\Model\WishlistFactory;
use Magento\Wishlist\Model\ResourceModel\Wishlist;
use Magento\Customer\Model\Session;
use Chupaprecios\ProductSearch\Model\AmazonSearch;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Message\ManagerInterface;
use Chupaprecios\ProductSearch\Helpers\CalcPrice;
use Magento\Framework\App\Config\ScopeConfigInterface;

class WishlistAdd extends \Magento\Framework\App\Action\Action
{
    /**
     * @var PageFactory
     */
    protected $pageFactory;
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;
    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;
    /**
     * @var Wishlist
     */
    protected $wishlistResource;
    /**
     * Session
     */
    protected $customerSession;
    /**
     * @var AmazonSearch
     */
    protected $amazonSearch;
    /**
     * @var Product
     */
    protected $magentoProduct;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var DirectoryList
     */
    protected $dir;
    /**
     * @var ObjectManagerInterface
     */
    protected $objectmanager;
    /**
     * @var JsonFactory;
     */
    protected $resultJsonFactory;
    /**
     * @var ManagerInterface
     */
    protected $messageManager;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    public function __construct(
        Context $context,
        ProductRepositoryInterface $productRepository,
        WishlistFactory $wishlistFactory,
        Wishlist $wishlistResource,
        Session $customerSession,
        AmazonSearch $amazonSearch,
        Product $magentoProduct,
        StoreManagerInterface $storeManager,
        DirectoryList $dir,
        ObjectManagerInterface $objectmanager,
        JsonFactory $resultJsonFactory,
        ManagerInterface $messageManager,
        ScopeConfigInterface $scopeConfig,
        File $file,
        PageFactory $pageFactory)
    {
        $this->file = $file;
        $this->pageFactory = $pageFactory;
        $this->productRepository = $productRepository;
        $this->wishlistFactory  = $wishlistFactory;
        $this->wishlistResource = $wishlistResource;
        $this->customerSession = $customerSession;
        $this->amazonSearch = $amazonSearch;
        $this->magentoProduct = $magentoProduct;
        $this->storeManager = $storeManager;
        $this->dir = $dir;
        $this->objectManager = $objectmanager;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->messageManager = $messageManager;
        $this->scopeConfig = $scopeConfig;
        $this->resultFactory = $context->getResultFactory();
        parent::__construct($context);
    }

    public function execute()
    {
        $params = $this->getRequest()->getParams();
        $asin = '';
        $customerId = '';
        $status = '';
        $resultJson = $this->resultJsonFactory->create();
        if($this->customerSession->isLoggedIn()){
            $customerId = $this->customerSession->getCustomer()->getId();
        }
        if(isset($params['asin'])){
            $asin = $params['asin'];
            //$product = $this->productRepository->get($asin);
            if($this->magentoProduct->getIdBySku($asin)){
                $product = $this->productRepository->get($asin);
                $status = $this->saveProductToWishlist($product, $customerId);
            } else{
                $prod = $this->loadProductBySku($asin);

                if(!$prod){
                    $productFromApi = $this->amazonSearch->getProductResult($asin);
                    $prod = $this->createProduct($productFromApi);
                }


                if($prod){
                    $product = $this->productRepository->get($asin);
                    $status = $this->saveProductToWishlist($product, $customerId);
                }
            }
        }
        if($status==1)
        {
            $message = _('Este producto ha sido agregado como favorito');
            return $resultJson->setData(['result' => 'true','message' => $message]);
        } else{
            return $resultJson->setData(['result' => 'false','message' => $status]);}
    }
    /**
     * @param $product
     * @param $customerId
     *
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function saveProductToWishlist($product, $customerId)
    {
        if(!$customerId){
            return false;
        }
        //load wishlist by customer id
        $wishlist = $this->wishlistFactory->create()->loadByCustomerId($customerId, true);

        //add product for wishlist
        try{
            $wishlist->addNewItem($product);

            //save wishlist
            $this->wishlistResource->save($wishlist);
            return true;
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    private function loadProductBySku($sku){
        $productModel = $this->objectManager->get('\Magento\Catalog\Model\Product');
        $product = $productModel->loadByAttribute('sku', $sku);
        return $product;
    }

    /**
     * @param $product
     *
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function createProduct($productFromApi){
        $sku = $productFromApi->asin;
        $priceDetails = $productFromApi->priceDetails;//CalcPrice::calc($variant->price, $this->scopeConfig);

        $state = $this->objectManager->get('Magento\Framework\App\State');
        if (!$state->getAreaCode()) {
            $state->setAreaCode('frontend');
        }

        $this->storeManager = $this->objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        $websiteId = $this->storeManager->getStore()->getWebsiteId();

        $_product = $this->objectManager->create('Magento\Catalog\Model\Product');
        $_product->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        $_product->setName($productFromApi->title . " " . $sku);
        $_product->setTypeId('simple');
        $_product->setAttributeSetId(4);
        $_product->setSku($sku);
        $_product->setWebsiteIds(array($websiteId));
        $_product->setVisibility(4);

        //$chapiStatus = $this->adminConfig["chapiStatus"];
        if(is_array($priceDetails)){
            if(isset($productFromApi->disscount) && $productFromApi->disscount){
                $_product->setPrice($productFromApi->disscount);
            }else{
                $_product->setPrice($priceDetails["total"]);
            }
            $_product->setAmazonPrice($priceDetails["amazon_price"]);
            $_product->setUtility($priceDetails["utility"]);
            $_product->setCommission($priceDetails["comision"]);
            $_product->setSupplies($priceDetails["suministros"]);
            $_product->setTotalCost($priceDetails["total_cost"]);
        }else{
            $_product->setPrice($priceDetails->total);
            $_product->setAmazonPrice($priceDetails->amazon_price);
            $_product->setUtility($priceDetails->utility);
            $_product->setCommission($priceDetails->comision);
            $_product->setSupplies($priceDetails->suministros);
            $_product->setTotalCost($priceDetails->total_cost);
        }

        $_product->setWeight(0);

        if (isset($variant->package_dimensions) && $variant->package_dimensions) {
            if (isset($variant->package_dimensions->weight) && $variant->package_dimensions->weight) {
                $_product->setWeight($variant->package_dimensions->weight->amount);
            }
        }

        $_product->setStockData(array(
                'use_config_manage_stock' => 0, //'Use config settings' checkbox
                'manage_stock' => 1, //manage stock
                'min_sale_qty' => 1, //Minimum Qty Allowed in Shopping Cart
                'max_sale_qty' => 20, //Maximum Qty Allowed in Shopping Cart
                'is_in_stock' => 1, //Stock Availability
                'qty' => 90000 //qty
            )
        );

        $images = @json_decode($productFromApi->images);
        $imagePath = false;
        if($images && count($images)){

            $path = $this->dir->getRoot() . DIRECTORY_SEPARATOR . "pub" . DIRECTORY_SEPARATOR . "media" . DIRECTORY_SEPARATOR . "wysiwyg" . DIRECTORY_SEPARATOR;
            if(!is_dir($path))
            {
                $this->file->mkdir($path,0777);
            }
            $newFileName = $sku . ".jpg";
            $imageString = @file_get_contents($images[0]->large);
            if ($imageString) {
                $save = file_put_contents($path . $newFileName, $imageString);
                if ($save) {
                    $imagePath = $path . $newFileName;
                }
            }

            if ($imagePath) {
                $_product->addImageToMediaGallery($imagePath, array('image', 'small_image', 'thumbnail'), true, false);
            }
        }

        $save = $_product->save();

        return $_product;

    }
}
