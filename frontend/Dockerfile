FROM node:22 AS builder

ARG APP_VERSION=unknown
ENV APP_VERSION=$APP_VERSION

WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
COPY prod.env .env
RUN npm run build

# Use a minimal nginx image and remove unnecessary files
FROM nginx:alpine
# Remove default nginx config to avoid conflicts
RUN rm -rf /etc/nginx/conf.d/default.conf
RUN rm -rf /usr/share/nginx/html/*
COPY --from=builder /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]