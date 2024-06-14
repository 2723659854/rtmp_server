##  rtmp_server

### 简介
 一个使用纯php开发的rtmp直播服务器，支持rtmp协议推流，rtmp拉流，支持flv格式拉流，可以使用http或者ws协议拉流。<br>

### 安装
```bash 
composer create-project xiaosongshu/rtmp_server
```

### 环境配置
本项目使用php8.1，建议新手使用<a href = "https://www.xp.cn/">phpstudy</a>这个集成环境，一键搞定环境搭建工作。<br>
或者使用本项目提供的<a href = "https://www.docker.com/">docker</a>环境，在本项目根目录下命令行执行以下命令：
```bash 
docker-compose up -d
```
本项目自带的docker配置已经集成了php相关扩展和ffmpeg。<br>
本项目默认使用三个端口：
```text
1935:rtmp服务
8501:flv服务
80:web服务
```
### 开启服务
进入本项目的根目录，在命令行执行以下命令：
```bash 
php server.php
```
### 关闭服务
windows系统 
```bash 
ctrl + c 
```
linux系统
```bash 
kill -9 pid
```
### 推流

```txt 
推流地址：rtmp://127.0.0.1/a/b

其中a是应用名称，b是频道名称，这两个参数可以改变，但只能是英文或者数字。可以自行修改。
```
我自己调试使用的OBS，使用方法可以参考
<a href="https://www.tencentcloud.com/zh/document/product/267/31569">网上教程</a>，
OBS工具<a href ="https://obsproject.com/">下载</a>。<br>
你也可以使用<a href="https://ffmpeg.org/">ffmpeg</a>工具，命令如下
```bash 
ffmpeg -re -stream_loop 1  -i "movie.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1/a/b
```
命令详解
```text
-re：表示以实时模式运行 FFMpeg。
-stream_loop 1：设置流循环次数为1。
-i "movie.mp4"：指定输入文件为"movie.mp4"。
-vcodec h264：强制使用 H.264 视频编解码器进行编码。
-acodec aac：强制使用 AAC 音频编解码器进行编码。
-f flv：指定输出格式为 FLV。
rtmp://127.0.0.1/a/b：指定 RTMP 服务器的地址和路径。
```

### 拉流
```text
rtmp: rtmp://127.0.0.1/a/b

flv(http): http://127.0.0.1:8501/a/b.flv

flv(ws): ws://127.0.0.1:8501/a/b.flv

hls：http://127.0.0.1:80/a/b.m3u8 （需要使用ffmpeg转换协议）
```
播放工具可以使用:<br>
<a href="https://get.videolan.org/vlc/3.0.20/win64/vlc-3.0.20-win64.exe">VLC</a>打开网络串流地址<br>
<a href="https://ffmpeg.org/">ffplay</a> ``` ffplay rtmp://127.0.0.1/a/b ```<br>
本项目提供网页播放，直接使用浏览器打开index.html即可，访问地址 `http://127.0.0.1:80/index.html` 。<br>
本项目提供网页播放，直接使用浏览器打开play.html即可，访问地址 `href="http://127.0.0.1:80/play.html` 。<br>

### 延迟问题

在理想状态的情况下测试，延迟在1秒以内。理想状态就是：直播服务器和推流，拉流都在同一台电脑，减少了网络波动的影响。<br>
另外，如果使用浏览器播放，某些浏览器如果切换到后台，因为节能的关系，浏览器不会播放直播，当浏览器切换到前台才会接着上一次的位置重新播放，这样子延迟
就相当高了。<br>
当然了如果服务端处理器性能拉胯，延迟也会很高，因为直播服务很耗性能的。

### 关于对hls协议的支持

本项目非常抱歉没有实现对hls的支持，但是提供了一个解决办法，使用ffmpeg工具对协议的转换，以上面的`rtmp://127.0.0.1/a/b`流为例，本项目默认将
hls协议的文件保存在hls目录下，所以你需要在项目根目录手动创建一个/a/目录。然后在命令行执行以下命令即可完成rtmp协议转换为hls协议。
```bash 
ffmpeg -i rtmp://127.0.0.1/a/b -c:v h264 -c:a aac -f hls -hls_time 3 -hls_list_size 0   ./a/b.m3u8
```
若需要退出协议转换，请在命令行输入`q`即可退出。
```ps
注意：如果需要使用hls协议，那么创建流的时候，应用名称（比如上面的变量a）要避免使用本项目的目录，否则会污染项目。需要避开的关键字如下所示：
MediaServer,public,Root,SabreAMF,vendor。另外不建议使用php语言相关关键字，可能后期拓展会用到。
```

命令详解

```text
-i rtmp://127.0.0.1/a/b              rtmp输入流，即rtmp拉流地址                     可以修改
-c:v h264                            选择视频编码方式为h264                         不用修改
-c:a aac                             选择音频编码方式为aac                          不用修改
-f hls                               指定输出格式为hls                             不用修改
-hls_time 3                          设置hls切片的时间间隔为1秒                      可以修改
-hls_list_size 0                     设置hls播放列表的大小为0，即只生成一个播放列表文件  不用修改
./a/b.m3u8                           指定输出文件的路径和名称                        可以修改
```



### 直播代理转发服务
本项目提供直播转发服务，使用多进程转发音视频数据。因为如果所有的播放器都接入到主进程，一旦播放器客户端数量上升到一定数量级后，只靠一个进程处理延迟会很高。
所以加入了子进程转发数据的服务。开启子进程转发服务后，播放器客户端可以接入到子进程。<br>
#### 操作方法
启动主进程服务
```bash 
php server.php
```
启动子进程服务
```bash 
php flv.php
```
子进程转发的是flv数据流。子进程可以设置一个flv的端口，不要和主进程`server.php`一样。你可以开启多个子进程转发，每一个子进程的端口都不一样。你的播放器
可以接入子进程的服务器。<br>
比如：开启一个主进程服务推流服务器，开启一个子进程，端口好为`8504`,创建一个直播应用为`/a/b`,使用`ffmpeg`或者`obs`推流到服务器。那么子进程的拉流
地址是`http://127.0.0.1:8504/a/b.flv` 。如果播放不了，是因为转发延迟，刷新一下重新播放。<br>

本项目提供了两个测试播放转发数据页面。访问地址是：`http://127.0.0.1:80/daili.html` 和 `http://127.0.0.1:80/flv.html` 。

#### 转发数据存在的问题

转发后的数据，可能是因为数据还原有问题，或者丢失了关键帧，或者关键帧之间的数据顺序错乱了，或者关键帧之间的时间相距过长，导致花屏马赛克，不清晰。
尚未找到具体原因，等待后面解决吧。

### 其他

需要注意的是，本项目使用的是php的cli模式，和传统的fpm模式有根本的区别。
如果在运行的时候报错，请检查报错信息，多半是缺少php扩展，根据报错信息安装对应扩展，如果使用本项目的docker配置，一般不会报错的。<br>
本项目已经添加了自定义的hls协议，在`Root\HLSDemo::class`，开启hls协议在`MediaServer::publisherOnFrame()`方法里面。不过本协议尚且有问题。有兴趣的
朋友可以帮忙修正一下。

### 声明

本项目只用于学习，里面很多资料来源于网络，如有侵权，请联系删除。本项目完全开源，用于相互学习交流，如有问题，欢迎联系我。

### 联系我
```txt
email: 2723659854@qq.com  171892716@qq.com
```

 

 
 

 
 
 
 