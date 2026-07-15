<?php
/**
 * Minimal runtime stubs for the Magento classes/interfaces mocked by the unit
 * suite. They exist ONLY so PHPUnit's mock generator can resolve the type and
 * its public method signatures without a real Magento installation.
 *
 * Every declaration is guarded with *_exists(), so when the real Magento
 * framework is autoloadable (running locally against a full install) the
 * genuine definitions are used and these stubs are skipped. In credential-free
 * CI, where Magento is absent, these provide just enough surface to mock.
 *
 * Not shipped in the package (Test/ is excluded) and never used in production.
 */

declare(strict_types=1);

namespace Magento\Framework\HTTP\Client {
    if (!class_exists(Curl::class, false)) {
        class Curl
        {
            public function setTimeout($value) {}
            public function setOption($option, $value) {}
            public function get($uri) {}
            public function post($uri, $params) {}
            public function getStatus() {}
            public function getBody() {}
            public function getHeaders() { return []; }
            public function addHeader($name, $value) {}
        }
    }
    if (!class_exists(CurlFactory::class, false)) {
        class CurlFactory
        {
            public function create(array $data = []): Curl { return new Curl(); }
        }
    }
}

namespace Magento\Framework\App {
    if (!class_exists(DeploymentConfig::class, false)) {
        class DeploymentConfig
        {
            public function get($key = null, $defaultValue = null) { return $defaultValue; }
            public function isAvailable(): bool { return true; }
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class, false)) {
        interface ScopeConfigInterface
        {
            public function getValue($path, $scopeType = 'default', $scopeCode = null);
            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
        }
    }
}

namespace Magento\Framework\Encryption {
    if (!interface_exists(EncryptorInterface::class, false)) {
        interface EncryptorInterface
        {
            public function encrypt($data);
            public function decrypt($data);
        }
    }
}

namespace Magento\Store\Api\Data {
    if (!interface_exists(StoreInterface::class, false)) {
        interface StoreInterface
        {
            public function getId();
            public function getCode();
            public function getName();
            public function getBaseUrl($type = 'link', $secure = null);
            public function setStoreId($storeId);
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(ScopeInterface::class, false)) {
        interface ScopeInterface
        {
            public const SCOPE_STORE = 'store';
            public const SCOPE_STORES = 'stores';
            public const SCOPE_WEBSITE = 'website';
            public const SCOPE_WEBSITES = 'websites';
        }
    }
    if (!interface_exists(StoreManagerInterface::class, false)) {
        interface StoreManagerInterface
        {
            public function getStore($storeId = null);
            public function getStores($withDefault = false, $codeKey = false);
            public function setCurrentStore($store);
        }
    }
}

namespace Magento\Catalog\Model\ResourceModel\Product {
    if (!class_exists(Collection::class, false)) {
        class Collection implements \IteratorAggregate, \Countable
        {
            public function setStoreId($storeId): self { return $this; }
            public function addAttributeToFilter($attribute, $condition = null, $joinType = 'inner'): self { return $this; }
            public function addAttributeToSelect($attribute, $joinType = false): self { return $this; }
            public function setPageSize($size): self { return $this; }
            public function setCurPage($page): self { return $this; }
            public function getSize(): int { return 0; }
            public function getIterator(): \Iterator { return new \ArrayIterator([]); }
            public function count(): int { return 0; }
        }
    }
    if (!class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            public function create(array $data = []): Collection { return new Collection(); }
        }
    }
}

namespace Magento\Catalog\Model\ResourceModel\Category {
    if (!class_exists(Collection::class, false)) {
        class Collection implements \IteratorAggregate, \Countable
        {
            public function setStoreId($storeId): self { return $this; }
            public function addAttributeToFilter($attribute, $condition = null, $joinType = 'inner'): self { return $this; }
            public function addAttributeToSelect($attribute, $joinType = false): self { return $this; }
            public function getSize(): int { return 0; }
            public function getIterator(): \Iterator { return new \ArrayIterator([]); }
            public function count(): int { return 0; }
        }
    }
    if (!class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            public function create(array $data = []): Collection { return new Collection(); }
        }
    }
}

namespace Magento\Cms\Model\ResourceModel\Page {
    if (!class_exists(Collection::class, false)) {
        class Collection implements \IteratorAggregate, \Countable
        {
            public function addFieldToFilter($field, $condition = null): self { return $this; }
            public function getSize(): int { return 0; }
            public function getIterator(): \Iterator { return new \ArrayIterator([]); }
            public function count(): int { return 0; }
        }
    }
    if (!class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            public function create(array $data = []): Collection { return new Collection(); }
        }
    }
}
