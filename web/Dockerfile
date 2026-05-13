FROM node:20-alpine AS builder
WORKDIR /app
COPY package*.json ./
# 设置构建时的环境变量，让前端知道 API 在哪里
ARG VITE_API_BASE
ARG VITE_WS_BASE
ENV VITE_API_BASE=${VITE_API_BASE}
ENV VITE_WS_BASE=${VITE_WS_BASE}
RUN npm install
COPY . .
RUN npx vite build

FROM nginx:alpine
# 将编译好的文件拷入 Nginx
COPY --from=builder /app/dist /usr/share/nginx/html
# 拷贝 Nginx 配置（用于支持 Vue 路由）
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]