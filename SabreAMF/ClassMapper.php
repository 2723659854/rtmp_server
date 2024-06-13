<?php

    /**
     * SabreAMF_ClassMapper
     *
     * @package SabreAMF
     * @version $Id: ClassMapper.php 233 2009-06-27 23:10:34Z evertpot $
     * @copyright Copyright (C) 2006-2009 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/)
     * @licence http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     */

    require_once dirname(__FILE__) . '/AMF3/RemotingMessage.php';
    require_once dirname(__FILE__) . '/AMF3/CommandMessage.php';
    require_once dirname(__FILE__) . '/AMF3/AcknowledgeMessage.php';
    require_once dirname(__FILE__) . '/AMF3/ErrorMessage.php';
    require_once dirname(__FILE__) . '/ArrayCollection.php';

    /** amf命令映射表 */
    final class SabreAMF_ClassMapper {

        /**
         * 系统默认提供的amf命令
         * @var array
         */
        static public $maps = array(
            'flex.messaging.messages.RemotingMessage'    => 'SabreAMF_AMF3_RemotingMessage',
            'flex.messaging.messages.CommandMessage'     => 'SabreAMF_AMF3_CommandMessage',
            'flex.messaging.messages.AcknowledgeMessage' => 'SabreAMF_AMF3_AcknowledgeMessage',
            'flex.messaging.messages.ErrorMessage'       => 'SabreAMF_AMF3_ErrorMessage',
            'flex.messaging.io.ArrayCollection'          => 'SabreAMF_ArrayCollection'
        );

        /**
         * Assign this callback to intercept calls to getLocalClass
         * 分配此回调以拦截对getLocalClass的调用
         * @var callback
         */
        static public $onGetLocalClass;

        /**
         * Assign this callback to intercept calls to getRemoteClass
         * 设置此回调以拦截对getRemoteClass的调用
         * @var callback
         */
        static public $onGetRemoteClass;

        /**
         * The Constructor
         *
         * We make the constructor private so the class cannot be initialized
         * 将构造函数私有化，避免被初始化
         *
         * @return void
         */
        private function __construct() { }

        /**
         * 注册对象
         * Register a new class to be mapped
         *
         * @param string $remoteClass
         * @param string $localClass
         * @return void
         */
        static public function registerClass($remoteClass,$localClass) {

            self::$maps[$remoteClass] = $localClass;

        }

        /**
         * 获取本地已保存的远程对象
         * Get the local classname for a remote class
         *
         * This method will return FALSE when the class is not found
         *
         * @param string $remoteClass
         * @return mixed
         */
        static public function getLocalClass($remoteClass) {

            $localClass = false;
            $cb = false;
            $localClass=(isset(self::$maps[$remoteClass]))?self::$maps[$remoteClass]:false;
            if (!$localClass && is_callable(self::$onGetLocalClass)) {
                $cb = true;
                $localClass = call_user_func(self::$onGetLocalClass,$remoteClass);
            }
            if (!$localClass) return false;
            if (!is_string($localClass) && $cb) {
                throw new Exception('Classname received from onGetLocalClass should be a string or return false. ' . gettype($localClass) . ' was returned');
            }
            if (!class_exists($localClass)) {
                throw new Exception('Class ' . $localClass . ' is not defined');
            }
            return $localClass;

        }

        /**
         * 获取远程调用对象
         * Get the remote classname for a local class
         *
         * This method will return FALSE when the class is not found
         *
         * @param string $localClass
         * @return mixed
         */
        static public function getRemoteClass($localClass) {

            $remoteClass = false;
            $cb = false;
            $remoteClass = array_search($localClass,self::$maps);
            if (!$remoteClass && is_callable(self::$onGetRemoteClass)) {
                $cb = true;
                $remoteClass = call_user_func(self::$onGetRemoteClass,$localClass);
            }
            if (!$remoteClass) return false;
            if (!is_string($remoteClass) && $cb) {
                throw new Exception('Classname received from onGetRemoteClass should be a string or return false. ' . gettype($remoteClass) . ' was returned');
            }
            return $remoteClass;

        }

    }


