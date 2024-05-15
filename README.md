##  rtmp_server

### 简介
 一个使用纯php开发的rtmp直播服务器，支持rtmp协议推流，rtmp拉流，支持flv格式拉流，可以使用http或者ws协议拉流。<br>

### 安装
```bash 
composer create-project xiaosongshu/rtmp_server
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

其中a是路径，b是直播名称，这两个参数可以改变，但只能是英文或者数字。可以自行修改。
```
我自己调试使用的OBS，使用方法可以参考
<a href="https://www.tencentcloud.com/zh/document/product/267/31569">网上教程</a>，
OBS工具<a href ="https://obsproject.com/">下载</a>。<br>
你也可以使用ffmpeg工具，命令如下
```bash 
ffmpeg -re -stream_loop 1  -i "movie.mp4" -vcodec h264 -acodec aac -f flv rtmp://127.0.0.1/a/b
```

### 拉流
```text
rtmp播放地址: rtmp://127.0.0.1/a/b

httpflv播放地址: http://127.0.0.1:8501/a/b.flv

wsflv播放地址: ws://127.0.0.1:8501/a/b.flv
```
播放工具可以使用<a href="https://get.videolan.org/vlc/3.0.20/win64/vlc-3.0.20-win64.exe">VLC</a>，
ffplay，本项目提供网页播放，直接使用浏览器打开index.html即可<a href="./index.html">播放</a>。

### 其他

 需要注意的是，本项目使用的是php的cli模式，和传统的fpm模式有根本的区别。
 如果在运行的时候报错，请检查报错信息，多半是缺少php扩展，建议新手使用<a href = "https://www.xp.cn/">phpstudy</a>这个集成环境，一键搞定环境搭建工作。<br>
 

 
 

 
 
 
 