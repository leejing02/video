<script setup lang="ts">
import { ref } from 'vue'
import AvatarContent from './AvatarContent.vue'
import { shortVideoApi } from '@/api/shortVideo'
import type { ShortVideoComment } from '@/types/models'
const props=defineProps<{ comment: ShortVideoComment; videoId:number; authorId?:number; depth?:number }>()
const emit=defineEmits<{reply:[comment:ShortVideoComment]}>()
const children=ref<ShortVideoComment[]>([]), expanded=ref(false), busy=ref(false), more=ref(true), error=ref('')
let page=0
async function load(){
 if(busy.value)return
 busy.value=true;error.value=''
 try{const res=await shortVideoApi.comments(props.videoId,{parent_id:props.comment.id,page:page+1,per_page:20});const rows=res.data||[];children.value.push(...rows);page++;more.value=rows.length===20;expanded.value=true}catch{error.value='回复加载失败，请重试'}finally{busy.value=false}
}
</script>
<template>
 <article class="comment-node">
  <div class="comment-line"><div class="comment-avatar"><AvatarContent :src="comment.user?.avatar" :name="comment.user?.name || comment.user?.username" /></div><div class="comment-copy"><span class="comment-name">{{comment.user?.name || comment.user?.username || '用户'}}</span><span v-if="authorId && comment.user_id===authorId" class="author-badge">作者</span><p>{{comment.content}}</p><div class="comment-meta"><time>{{new Date(comment.created_at).toLocaleDateString('zh-CN')}}</time><button @click="emit('reply',comment)">回复</button><span>♡ {{comment.likes || 0}}</span></div></div></div>
  <div :style="{marginLeft:(depth||0)<2?'36px':'0'}"><button class="reply-expand" v-if="!expanded" @click="load">{{busy?'加载中…':'展开回复'}}⌄</button><template v-else><PlayerComment v-for="child in children" :key="child.id" :comment="child" :video-id="videoId" :author-id="authorId" :depth="(depth||0)+1" @reply="emit('reply',$event)"/><p v-if="!children.length" class="reply-expand">暂无回复</p><button v-if="more" class="reply-expand" @click="load">{{busy?'加载中…':'展开更多回复'}}</button><button class="reply-expand" @click="expanded=false">收起⌃</button></template><button v-if="error" @click="load">{{error}}</button></div>
 </article>
</template>
<style scoped>
.comment-node{padding:10px 0}.comment-line{display:flex;gap:10px}.comment-avatar{position:relative;width:34px;height:34px;flex-shrink:0;border-radius:50%;background:#eee;overflow:hidden;display:grid;place-items:center}.comment-avatar :deep(img){width:100%;height:100%;object-fit:cover}.comment-copy{flex:1;min-width:0}.comment-name{font-size:13px;color:#8e8e93}.author-badge{display:inline-block;margin-left:6px;background:#ff315b;color:#fff;border-radius:3px;padding:1px 5px;font-size:10px;line-height:16px;vertical-align:1px}.comment-copy p{white-space:pre-wrap;font-size:15px;line-height:1.6;overflow-wrap:anywhere;margin:3px 0 9px}.comment-meta{display:flex;gap:16px;color:#999;font-size:12px}.comment-meta span{margin-left:auto}.reply-expand{color:#888;font-size:12px;margin:8px 0 0 6px}
</style>
