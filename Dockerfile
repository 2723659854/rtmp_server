# 使用阿尔法版本，镜像体积更小，这里面不使用apt-get  而是使用apk命令安装包 参考地址：https://blog.51cto.com/zhangxueliang/4941632
# 这里强制指定版本为 8.1.24-cli-alpine ，版本高了之后，PHP自带的函数有问题
FROM php:8.1.24-cli-alpine
# 使用sed命令修改镜像文件 这一句命令的意思是，搜索文件/etc/apk/repositories，找到s/dl-cdn.alpinelinux.org替换为mirrors.aliyun.com/g
# 语法 RUN sed -i '/要匹配的内容/i修改后的内容' 文件路径 （参考地址：https://blog.csdn.net/qq_29229567/article/details/107684952）
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories && apk update && \
    # 安装基础依赖,--no-cache 表示不使用缓存，每次都拉取最新的包
    apk add --no-cache \
    # apk add  \
    # 管理shell脚本的工具
    autoconf \
    # 创建一个base的基本镜像文件
    build-base \
    # 安装一个php的event扩展
    libevent-dev \
    # 安装mysqli扩展
    # 安装一个UUID扩展
    libuuid \
    # 安装一个管理ext2文件的管理工具
    e2fsprogs-dev \
    # 安装一个zip压缩包扩展
    libzip-dev \
    # 安装一个OpenSSL扩展
    openssl-dev \
    # 安装一个sql插件 预处理sql
    libpq-dev \
    # 安装一个rabbitmq 扩展
    rabbitmq-c-dev \
    # 安装一个png图像处理扩展
    libpng-dev \
    # 安装webp图像处理扩展
    libwebp-dev \
    # memcache扩展
    # libmemcached-dev \
    # 安装一个jpg格式图像处理扩展
    libjpeg-turbo-dev \
    # 安装一个免费的字体库 freetype
    freetype-dev && \
    # 配置GD库 用来预处理图片
    docker-php-ext-configure gd \
    # gd 使用的图像处理扩展
    --with-jpeg=/usr/include/ \
    # gd 使用的字体扩展
    --with-freetype=/usr/include/ && \
    # 安装php扩展 bcmath：数学函数扩展
    docker-php-ext-install sockets pcntl pdo_mysql mysqli pdo_pgsql bcmath zip gd  && \
    # 安装pecl扩展 这里安装了apcu内存缓存扩展
    pecl install redis mongodb uuid amqp event apcu&& \
    # 启用pecl扩展 开启扩展
    docker-php-ext-enable redis mongodb uuid amqp apcu&& \
    # 启用event
    docker-php-ext-enable --ini-name event.ini event && \
    # 安装composer 这里使用的curl -o 保存路径  下载地址  意思是：将文件从这里下载后保存到指定目录 ，后面的命令是给这个目录添加可执行权限
    curl -o /usr/local/bin/composer https://mirrors.aliyun.com/composer/composer.phar && chmod +x /usr/local/bin/composer

# 安装git
RUN apk add git

# 下载memcache源代码
RUN git clone https://github.com/websupport-sk/pecl-memcache.git /usr/src/php/ext/memcache

# 安装PHP扩展
RUN docker-php-ext-install /usr/src/php/ext/memcache
# 安装libmemcached依赖
RUN apk add --no-cache libmemcached libmemcached-dev

#下载memcached源代码
RUN git clone https://github.com/php-memcached-dev/php-memcached.git /usr/src/php/ext/memcached
# 安装memcached扩展
RUN docker-php-ext-install /usr/src/php/ext/memcached

# 安装ffmpeg
RUN apk add ffmpeg

# 将当前的文件复制到指定目录，如果使用了任务编排，这个copy代码是无效的
# COPY . /usr/src/myapp
# 指定工作目录
WORKDIR /usr/src/myapp
# 需要挂载的目录
VOLUME /usr/src/myapp
# 需要暴露的端口
EXPOSE 8080
EXPOSE 6379
# 需要执行的命令
#CMD [ "pwd" ]
#CMD [ "cd webman" ]
#CMD [ "php", "./start.php start" ]
# 覆盖启动命令，不再直接启动服务器，而是用一个空进程维持容器运行
# 指定退出命令
STOPSIGNAL SIGKILL
# 使用tail -f /dev/null 维持容器持续运行
CMD tail -f /dev/null