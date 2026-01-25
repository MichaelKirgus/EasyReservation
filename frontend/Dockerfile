ARG APP_VERSION=unknown
ENV APP_VERSION=$APP_VERSION

FROM node:22 AS builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
COPY prod.env .env
RUN npm run build
FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]