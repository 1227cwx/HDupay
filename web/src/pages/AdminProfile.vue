<template>
  <n-grid cols="1 l:2" responsive="screen" :x-gap="18" :y-gap="18">
    <n-gi>
      <n-card title="管理员信息" :bordered="false">
        <n-space vertical size="large">
          <n-thing>
            <template #avatar>
              <n-avatar round color="#2563eb" :size="44"><n-icon size="24"><PersonCircleOutline /></n-icon></n-avatar>
            </template>
            <template #header>{{ current.nickname || current.username || '管理员' }}</template>
          </n-thing>
          <n-descriptions label-placement="left" bordered :column="1">
            <n-descriptions-item label="管理员 ID">{{ current.id || '-' }}</n-descriptions-item>
            <n-descriptions-item label="账号状态"><n-tag :type="current.status === 'active' ? 'success' : 'default'" :bordered="false">{{ current.status || '-' }}</n-tag></n-descriptions-item>
            <n-descriptions-item label="最后登录">{{ current.last_login_at || '-' }}</n-descriptions-item>
          </n-descriptions>
          <n-form :model="profileForm" label-placement="top">
            <n-form-item label="管理员账号"><n-input v-model:value="profileForm.username" placeholder="3-64 位字母、数字或下划线" /></n-form-item>
            <n-form-item label="管理员昵称"><n-input v-model:value="profileForm.nickname" placeholder="请输入后台显示名称" /></n-form-item>
            <n-form-item><n-button type="primary" :loading="profileSaving" @click="saveProfile"><template #icon><n-icon><SaveOutline /></n-icon></template>保存管理员信息</n-button></n-form-item>
          </n-form>
        </n-space>
      </n-card>
    </n-gi>
    <n-gi>
      <n-card title="修改登录密码" :bordered="false">
        <n-space vertical size="large">
          <n-thing>
            <template #avatar><n-avatar round color="#f59e0b" :size="44"><n-icon size="24"><LockClosedOutline /></n-icon></n-avatar></template>
            <template #header>密码安全</template>
          </n-thing>
          <n-form :model="passwordForm" label-placement="top">
            <n-form-item label="原密码"><n-input v-model:value="passwordForm.old_password" type="password" show-password-on="click" placeholder="请输入当前密码" /></n-form-item>
            <n-form-item label="新密码"><n-input v-model:value="passwordForm.new_password" type="password" show-password-on="click" placeholder="至少 8 个字符" /></n-form-item>
            <n-form-item label="确认新密码"><n-input v-model:value="passwordForm.confirm_password" type="password" show-password-on="click" placeholder="请再次输入新密码" @keydown.enter="savePassword" /></n-form-item>
            <n-form-item><n-button type="primary" :loading="passwordSaving" @click="savePassword"><template #icon><n-icon><SaveOutline /></n-icon></template>修改密码</n-button></n-form-item>
          </n-form>
        </n-space>
      </n-card>
    </n-gi>
    <n-gi>
      <n-card title="站点设置" :bordered="false">
        <n-space vertical size="large">
          <n-thing>
            <template #avatar><n-avatar round color="#16a34a" :size="44"><n-icon size="24"><GlobeOutline /></n-icon></n-avatar></template>
            <template #header>公开访问地址</template>
          </n-thing>
          <n-form :model="siteForm" label-placement="top">
            <n-form-item label="公开访问地址"><n-input v-model:value="siteForm.public_base_url" placeholder="例如：https://cwx-u.jkeyun.com" clearable /></n-form-item>
            <n-form-item label="后台访问域名"><n-input v-model:value="siteForm.admin_allowed_domain" placeholder="为空不限制，例如：admin.example.com" clearable /></n-form-item>
            <n-form-item label="开放 Pay 公开页面"><n-switch v-model:value="siteForm.pay_public_enabled" :checked-value="1" :unchecked-value="0" /></n-form-item>
            <n-form-item><n-button type="primary" :loading="siteSaving" @click="saveSite"><template #icon><n-icon><SaveOutline /></n-icon></template>保存站点设置</n-button></n-form-item>
          </n-form>
        </n-space>
      </n-card>
    </n-gi>
  </n-grid>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useMessage } from 'naive-ui'
import { GlobeOutline, LockClosedOutline, PersonCircleOutline, SaveOutline } from '@vicons/ionicons5'
import { api } from '../api'

const router = useRouter()
const message = useMessage()
const current = ref<any>({})
const profileSaving = ref(false)
const passwordSaving = ref(false)
const siteSaving = ref(false)
const profileForm = reactive({ username: '', nickname: '' })
const passwordForm = reactive({ old_password: '', new_password: '', confirm_password: '' })
const siteForm = reactive({ public_base_url: '', admin_allowed_domain: '', pay_public_enabled: 1 })

async function loadCurrent() {
  const data: any = await api.get('/admin/auth/me')
  if (!data || !data.id) {
  await router.push('/hdupay/login')
    return
  }
  current.value = data
  profileForm.username = data.username || ''
  profileForm.nickname = data.nickname || ''
}

async function loadSite() {
  try {
    const data: any = await api.get('/admin/system/settings')
    siteForm.public_base_url = data?.site?.public_base_url || ''
    siteForm.admin_allowed_domain = data?.site?.admin_allowed_domain || ''
    siteForm.pay_public_enabled = Number(data?.site?.pay_public_enabled ?? 1)
  } catch (e: any) {
    message.error(e.message)
  }
}

async function saveSite() {
  siteSaving.value = true
  try {
    const data: any = await api.post('/admin/system/site/save', { public_base_url: siteForm.public_base_url, admin_allowed_domain: siteForm.admin_allowed_domain, pay_public_enabled: siteForm.pay_public_enabled })
    siteForm.public_base_url = data?.public_base_url || ''
    siteForm.admin_allowed_domain = data?.admin_allowed_domain || ''
    siteForm.pay_public_enabled = Number(data?.pay_public_enabled ?? 1)
    message.success('站点设置已保存')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    siteSaving.value = false
  }
}

async function saveProfile() {
  profileSaving.value = true
  try {
    current.value = await api.post('/admin/auth/profile/update', profileForm)
    message.success('管理员信息已保存')
    await loadCurrent()
    window.dispatchEvent(new Event('admin-profile-updated'))
  } catch (e: any) {
    message.error(e.message)
  } finally {
    profileSaving.value = false
  }
}

async function savePassword() {
  passwordSaving.value = true
  try {
    await api.post('/admin/auth/password/update', passwordForm)
    passwordForm.old_password = ''
    passwordForm.new_password = ''
    passwordForm.confirm_password = ''
    message.success('管理员密码已修改')
  } catch (e: any) {
    message.error(e.message)
  } finally {
    passwordSaving.value = false
  }
}

onMounted(() => {
  loadCurrent()
  loadSite()
})
</script>
