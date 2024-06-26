<?php

    require_once dirname(__FILE__) . '/Externalized.php';

    /**
     * 用于flex 命令
     * This is the default mapping for the flex.messaging.io.ArrayCollection class
     * It can be accessed using most of the normal array access methods
     * 在 AMF（Action Message Format）中，Flex 命令指的是一种用于 Adobe Flex 应用程序的特定命令和消息格式。
     * Flex 是一种基于 Flash 平台的开发框架，用于创建富互联网应用程序（RIA），其通信和数据交换可以使用 AMF 格式来进行序列化和传输。
     * @package SabreAMF
     * @uses SabreAMF_Externalized
     * @uses IteratorAggregate
     * @uses ArrayAccess
     * @uses Countable
     * @version $Id: ArrayCollection.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2007-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl)
     * @license licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     */
    class SabreAMF_ArrayCollection implements SabreAMF_Externalized, IteratorAggregate, ArrayAccess, Countable {

        /**
         * data
         *
         * @var array
         */
        private $data;

        /**
         * Construct this object
         *
         * @param array $data pass an array here to populate the array collection
         * @return void
         */
        function __construct($data = array()) {

            if (!$data) $data = array();
            $this->data = new ArrayObject($data);

        }

        /**
         * amf3 格式的数据反序列化用到
         * This is used by SabreAMF when this object is unserialized (from AMF3)
         *
         * @param array $data
         * @return void
         */
        function readExternal($data) {

            $this->data = new ArrayObject($data);

        }

        /**
         * amf格式序列化的时候用到
         * This is used by SabreAMF when this object is serialized
         *
         * @return array
         */
        function writeExternal() {

            return iterator_to_array($this->data);

        }

        /**
         * implemented from IteratorAggregate
         *
         * @return ArrayObject
         */
        #[ReturnTypeWillChange] function getIterator() {

            return $this->data;

        }

        /**
         * implemented from ArrayAccess
         *
         * @param mixed $offset
         * @return bool
         */
        #[ReturnTypeWillChange] function offsetExists(mixed $offset): bool
        {

            return isset($this->data[$offset]);

        }

        /**
         * Implemented from ArrayAccess
         *
         * @param mixed $offset
         * @return mixed
         */
        #[ReturnTypeWillChange] function offsetGet(mixed $offset): mixed
        {

            return $this->data[$offset];

        }

        /**
         * Implemented from ArrayAccess
         *
         * @param mixed $offset
         * @param mixed $value
         * @return void
         */
        #[ReturnTypeWillChange] function offsetSet(mixed $offset, mixed $value): void
        {

            if (!is_null($offset)) {
                $this->data[$offset] = $value;
            } else {
                $this->data[] = $value;
            }

        }

        /**
         * Implemented from ArrayAccess
         *
         * @param mixed $offset
         * @return void
         */
        #[ReturnTypeWillChange] function offsetUnset(mixed $offset): void
        {

            unset($this->data[$offset]);

        }

        /**
         * Implemented from Countable
         *
         * @return int
         */
        #[ReturnTypeWillChange] function count(): int
        {

            return count($this->data);

        }

    }


