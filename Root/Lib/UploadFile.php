<?php

namespace Root\Lib;

use Root\Lib\File;
use function pathinfo;

/**
 * @purpose 文件上传类
 * @comment 本项目实际上用不到文件操作，但是为了保证协议完整性而保留
 */
class UploadFile extends File
{
    /**
     * @var string 名称
     */
    protected $uploadName = null;

    /**
     * @var string 类型
     */
    protected $uploadMimeType = null;

    /**
     * @var int 错误码
     */
    protected $uploadErrorCode = null;

    /**
     * 初始化
     *
     * @param string $fileName
     * @param string $uploadName
     * @param string $uploadMimeType
     * @param int $uploadErrorCode
     */
    public function __construct(string $fileName, string $uploadName, string $uploadMimeType, int $uploadErrorCode)
    {
        $this->uploadName = $uploadName;
        $this->uploadMimeType = $uploadMimeType;
        $this->uploadErrorCode = $uploadErrorCode;
        parent::__construct($fileName);
    }

    /**
     * 获取上传文件名称
     * @return string
     */
    public function getUploadName(): ?string
    {
        return $this->uploadName;
    }

    /**
     * 获取文件类型
     * @return string
     */
    public function getUploadMimeType(): ?string
    {
        return $this->uploadMimeType;
    }

    /**
     * 获取扩展
     * @return string
     */
    public function getUploadExtension(): string
    {
        return pathinfo($this->uploadName, PATHINFO_EXTENSION);
    }

    /**
     * 获取错误码
     * @return int
     */
    public function getUploadErrorCode(): ?int
    {
        return $this->uploadErrorCode;
    }

    /**
     * 是否正确
     * @return bool
     * @comment 是否上传成功
     */
    public function isValid(): bool
    {
        return $this->uploadErrorCode === UPLOAD_ERR_OK;
    }

    /**
     * 获取mineType
     * @return string
     * @deprecated
     */
    public function getUploadMineType(): ?string
    {
        return $this->uploadMimeType;
    }
}