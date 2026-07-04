<template>
  <n-layout embedded>
    <n-layout-content content-style="min-height: 100vh; padding: 48px 16px; background: #f5f7fb;">
      <n-space justify="center">
        <n-space vertical size="large" :style="{ width: '420px', maxWidth: '100%' }">
          <n-card title="管理员登录" :bordered="false">
            <n-form :model="form" label-placement="top">
              <n-form-item label="管理员账号">
                <n-input v-model:value="form.username" placeholder="请输入管理员账号" />
              </n-form-item>
              <n-form-item label="管理员密码">
                <n-input v-model:value="form.password" type="password" show-password-on="click" placeholder="请输入管理员密码" @keydown.enter="login" />
              </n-form-item>
              <n-form-item>
                <n-button type="primary" block :loading="loading" @click="login">登录后台</n-button>
              </n-form-item>
            </n-form>
          </n-card>
        </n-space>
      </n-space>
    </n-layout-content>
  </n-layout>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useMessage } from 'naive-ui'
import { api } from '../api'

const router = useRouter()
const message = useMessage()
const loading = ref(false)
const form = reactive({ username: 'admin', password: '' })

async function login() {
  loading.value = true
  try {
    await api.post('/admin/auth/login', form)
    message.success('登录成功')
    await router.push('/hdupay/overview')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    loading.value = false
  }
}
</script>
