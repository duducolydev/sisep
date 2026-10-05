FROM node:22-alpine

RUN corepack enable && corepack prepare pnpm@10.30.3 --activate

WORKDIR /workspace

COPY pnpm-workspace.yaml package.json pnpm-lock.yaml* ./
COPY apps/web/package.json ./apps/web/package.json
COPY packages/shared/package.json ./packages/shared/package.json

RUN pnpm install --frozen-lockfile || pnpm install

COPY apps/web ./apps/web
COPY packages/shared ./packages/shared

WORKDIR /workspace/apps/web

EXPOSE 5173

CMD ["pnpm", "dev", "--", "--host", "0.0.0.0"]
