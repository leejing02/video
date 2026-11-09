<script setup lang="ts">
import { ref,onMounted,onUnmounted } from 'vue'
import PlayerComment from './PlayerComment.vue'
import AvatarContent from './AvatarContent.vue'
import { shortVideoApi } from '@/api/shortVideo'
import type { ShortVideo, ShortVideoComment } from '@/types/models'
const props=defineProps<{videoId:number;count:number;video:ShortVideo}>();const emit=defineEmits<{close:[];posted:[]}>()
const comments=ref<ShortVideoComment[]>([]),reply=ref<ShortVideoComment|null>(null),text=ref(''),busy=ref(false),loading=ref(false),error=ref(''),more=ref(true)
const composing=ref(false)
let page=0
let revision=0
async function load(){if(loading.value)return;loading.value=true;revision++;error.value='';try{const res=await shortVideoApi.comments(props.videoId,{page:page+1,per_page:20});comments.value.push(...res.data);page++;more.value=res.data.length===20}catch{error.value='评论加载失败，请重试'}finally{loading.value=false}}
async function send(){if(busy.value||composing.value||!text.value.trim())return;busy.value=true;revision++;error.value='';try{await shortVideoApi.createComment(props.videoId,{content:text.value.trim(),parent_id:reply.value?.id});emit('posted');text.value='';reply.value=null;page=0;comments.value=[];await load()}catch{error.value='发送失败，请确认已登录后重试'}finally{busy.value=false}}
let timer: ReturnType<typeof setInterval> | undefined
let disposed=false
async function syncComments(){
 if(document.hidden||loading.value||busy.value)return
 const version=revision
 try{const res=await shortVideoApi.comments(props.videoId,{page:1,per_page:20});if(disposed||version!==revision)return
 const ids=new Set(res.data.map(item=>item.id));comments.value=[...res.data,...comments.value.filter(item=>!ids.has(item.id))]
 }catch{ /* Keep the visible thread on temporary network failures. */ }
}
onMounted(()=>{void load();timer=setInterval(syncComments,15000)})
onUnmounted(()=>{disposed=true;clearInterval(timer)})
</script>
<template><div class="comments-panel" role="dialog" aria-modal="true" aria-label="视频评论"><header><strong>{{count}} 条评论</strong><button aria-label="关闭评论" @click="$emit('close')">×</button></header><div class="comment-scroll"><section v-if="video.user" class="video-author-summary" aria-label="视频作者与作品信息"><div class="summary-avatar"><AvatarContent :src="video.user.avatar" :name="video.user.name || video.user.username"/></div><div class="summary-copy"><div class="summary-name">{{video.user.name || video.user.username}}<span class="author-badge">作者</span></div><p>{{video.description || video.title}}</p></div></section><PlayerComment v-for="comment in comments" :key="comment.id" :comment="comment" :video-id="videoId" :author-id="video.user?.id" @reply="reply=$event"/><p v-if="!comments.length&&!loading">暂无评论，来说点什么吧</p><button v-if="more" @click="load">{{loading?'加载中…':'加载更多评论'}}</button><p v-if="error" role="alert">{{error}}</p></div><form @submit.prevent="send"><div v-if="reply" class="reply-target">回复 {{reply.user?.name || reply.user?.username}}<button type="button" @click="reply=null">×</button></div><div class="comment-compose"><input v-model="text" @compositionstart="composing=true" @compositionend="composing=false" maxlength="2000" :placeholder="reply?'回复这条评论…':'发条有爱评论～'" aria-label="评论内容"/><button :disabled="busy||!text.trim()">{{busy?'发送中':'发送'}}</button></div></form></div></template>
<style scoped>
.video-author-summary{display:flex;gap:12px;padding:12px 0 20px}.summary-avatar{width:38px;height:38px;flex:none;border-radius:50%;overflow:hidden;background:#eee;display:grid;place-items:center}.summary-avatar :deep(img){width:100%;height:100%;object-fit:cover}.summary-copy{flex:1;min-width:0}.summary-name{display:flex;align-items:center;flex-wrap:wrap;gap:6px;color:#8e8e93;font-size:13px}.author-badge{background:#ff315b;color:#fff;border-radius:3px;padding:1px 5px;font-size:10px;line-height:16px}.summary-copy p{margin:5px 0 0;color:#243e61;line-height:1.6;font-size:15px;white-space:pre-wrap;overflow-wrap:anywhere}
.comments-panel{height:68%;display:flex;flex-direction:column;background:#fff;color:#171717;border-radius:22px 22px 0 0;overflow:hidden}header{display:flex;justify-content:center;align-items:center;padding:18px;position:relative}header button{position:absolute;right:18px;font-size:28px}.comment-scroll{flex:1;min-height:0;overflow:auto;padding:0 18px}.comment-scroll>button,.comment-scroll>p{display:block;margin:18px auto;color:#888;font-size:13px}form{padding:12px 16px max(12px,env(safe-area-inset-bottom));border-top:1px solid #eee}.comment-compose{display:flex;background:#f5f5f5;border-radius:24px;padding:10px 16px;gap:8px}.comment-compose input{background:transparent;flex:1;min-width:0;color:#111;outline:none}.comment-compose button{color:#ff315b}.comment-compose button:disabled{color:#aaa}.reply-target{font-size:12px;color:#888;display:flex;justify-content:space-between;padding:0 8px 8px}
</style>
