export function registerChat(Alpine) {
  Alpine.data('messenger', data => ({
    inbox:data.inbox, query:data.q, selected:data.selected, messages:data.selected?.messages || [],
    peerRead:data.selected?.peer_read_through || 0, body:'', pending:null, sending:false,
    error:'', hasOlder:(data.selected?.messages.length || 0) >= data.page_size,
    loadingOlder:false, timer:null, polling:false, inboxRevision:0, readThrough:0, reading:false,
    drafts:{},
    init() {
      try { this.drafts=JSON.parse(sessionStorage.getItem(data.draft_key) || '{}'); } catch { this.drafts={}; }
      this.restoreDraft(); this.scroll();
      this.timer=setInterval(async()=>{
        if(document.hidden || this.polling) return;
        this.polling=true;
        try { await Promise.all([this.loadInbox(this.inbox.page),this.refresh()]); } finally { this.polling=false; }
      },data.poll_ms);
    },
    destroy(){clearInterval(this.timer);},
    persistDrafts(){try {sessionStorage.setItem(data.draft_key,JSON.stringify(this.drafts));}catch{}},
    saveDraft(){if(!this.selected)return; const old=this.drafts[this.selected.id];this.drafts[this.selected.id]={body:this.body,client:old?.body===this.body?old.client:null};this.persistDrafts();},
    restoreDraft(){this.body=this.selected?(this.drafts[this.selected.id]?.body || ''):'';this.pending=null;},
    async request(url,options={}){
      let response;
      try { response=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}}); } catch { throw new Error('Chưa thể kết nối. Vui lòng kiểm tra mạng rồi thử lại; nội dung chưa gửi vẫn được giữ.'); }
      if(!response.ok){
        const error=new Error([401,403,404,419].includes(response.status)?'Phiên đăng nhập hoặc quyền truy cập đã thay đổi. Vui lòng tải lại trang.':'Chưa thể kết nối. Nội dung chưa gửi được giữ lại để bạn thử lại.');error.status=response.status;
        if(response.status===422){const result=await response.json();error.message=Object.values(result.errors || {}).flat()[0] || 'Không thể thực hiện thao tác lúc này.';}
        throw error;
      }
      return response.json();
    },
    async loadInbox(page=1){
      const revision=++this.inboxRevision;
      try{const url=new URL(data.inbox_url,location.href);url.searchParams.set('q',this.query);url.searchParams.set('page',page);const result=await this.request(url);if(revision===this.inboxRevision){this.inbox=result;if(!this.selected)this.error='';}}
      catch(error){this.error=error.message;}
    },
    async open(item){
      if(this.sending || this.selected?.id===item.id)return;
      this.saveDraft(); this.error='';
      const id=item.id;this.selected={id,name:item.name,subtitle:item.subtitle,can_send:false};this.messages=[];this.readThrough=0;this.restoreDraft();
      try{
        const result=await this.request(item.messages_url);if(this.selected?.id!==id)return;
        this.selected={...result,messages_url:item.messages_url,send_url:item.send_url,read_url:item.read_url};
        this.messages=result.messages;this.peerRead=result.peer_read_through;this.hasOlder=this.messages.length>=data.page_size;
        history.replaceState(null,'',item.url);this.scroll();
      }catch(error){if(this.selected?.id===id)this.error=error.message;}
    },
    backToList(){if(this.sending)return;this.saveDraft();this.selected=null;this.messages=[];this.error='';history.replaceState(null,'',data.home_url);this.loadInbox(this.inbox.page);},
    scroll(){this.$nextTick(()=>{const box=this.$refs.messages;if(box){box.scrollTop=box.scrollHeight;this.acknowledge();}});},
    merge(rows){const known=new Set(this.messages.map(m=>m.id));this.messages=[...this.messages,...rows.filter(m=>!known.has(m.id))].sort((a,b)=>a.id-b.id);},
    async refresh(){
      const selected=this.selected;if(!selected?.messages_url)return;
      const box=this.$refs.messages;const bottom=box && box.scrollHeight-box.scrollTop-box.clientHeight<60;
      try{
        const after=this.messages.at(-1)?.id || 0;const result=await this.request(selected.messages_url+'?after='+after);
        if(this.selected?.id!==selected.id)return;
        this.merge(result.messages);this.peerRead=result.peer_read_through;this.selected.can_send=result.can_send;
        if(!this.pending)this.error='';if(bottom)this.scroll();
      }catch(error){if(this.selected?.id===selected.id){this.error=error.message;if([401,403,404,419].includes(error.status))this.selected.can_send=false;}}
    },
    async acknowledge(){
      const selected=this.selected,box=this.$refs.messages,through=this.messages.at(-1)?.id;
      if(document.hidden || !selected?.read_url || !box || box.scrollHeight-box.scrollTop-box.clientHeight>60 || !through || through<=this.readThrough || this.reading)return;
      this.reading=true;
      try{await this.request(selected.read_url,{method:'POST',body:JSON.stringify({through})});if(this.selected?.id===selected.id){this.readThrough=through;const item=this.inbox.items.find(i=>i.id===selected.id);if(item)item.unread=0;}}
      catch(error){if(this.selected?.id===selected.id)this.error=error.message;}finally{this.reading=false;}
    },
    async older(){
      if(this.loadingOlder || !this.messages.length)return;this.loadingOlder=true;const selected=this.selected;
      try{const box=this.$refs.messages,height=box.scrollHeight;const result=await this.request(selected.messages_url+'?before='+this.messages[0].id);if(this.selected?.id!==selected.id)return;this.merge(result.messages);this.hasOlder=result.messages.length>=data.page_size;this.$nextTick(()=>{box.scrollTop+=box.scrollHeight-height;});}
      catch(error){this.error=error.message;}finally{this.loadingOlder=false;}
    },
    retry(){if(!this.pending || this.sending)return;this.body=this.pending.body;this.drafts[this.selected.id]={body:this.body,client:this.pending.client};this.persistDrafts();this.send();},
    async send(){
      if(this.sending || !this.body.trim() || !this.selected?.can_send)return;
      this.saveDraft();const selected=this.selected,body=this.body.trim();
      const draft=this.drafts[selected.id];
      const client=draft.client || (crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g,c=>(c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)));
      draft.client=client;this.persistDrafts();this.pending={body,client};this.sending=true;this.error='';this.scroll();
      try{
        await this.request(selected.send_url,{method:'POST',body:JSON.stringify({body,client_id:client})});
        this.body='';delete this.drafts[selected.id];this.persistDrafts();this.pending=null;
        await this.refresh();this.scroll();await this.loadInbox(this.inbox.page);
      }catch(error){this.error=error.message;if([401,403,404,419].includes(error.status))this.selected.can_send=false;}finally{this.sending=false;}
    },
  }));
}
