<script setup lang="ts">
import { ref, computed } from 'vue'
import { post } from '@/api/client'

// ---- AI 自定义解梦 ----
const customDesc = ref('')
const customLoading = ref(false)
const customError = ref('')
const customResult = ref<{ traditional: string; freudian: string } | null>(null)
const customTab = ref<'traditional' | 'freudian'>('traditional')

async function interpretDream() {
  const desc = customDesc.value.trim()
  if (desc.length < 10) { customError.value = '请至少描述10个字的梦境内容'; return }
  customLoading.value = true
  customError.value = ''
  customResult.value = null
  try {
    const res = await post<{ traditional: string; freudian: string }>('/dreams/interpret', { description: desc })
    customResult.value = res
    customTab.value = 'traditional'
  } catch (e: any) {
    customError.value = e?.response?.data?.error ?? '解梦失败，请稍后重试'
  } finally {
    customLoading.value = false
  }
}

function clearCustom() {
  customDesc.value = ''
  customResult.value = null
  customError.value = ''
}

interface DreamEntry {
  keyword: string
  traditional: string
  freudian: string
}

const DREAMS: DreamEntry[] = [
  {
    keyword: '飞翔',
    traditional: '梦见飞翔，是吉祥之兆，象征自由、超越与志向高远。古人认为，飞翔之梦预示着事业上即将突破瓶颈，摆脱束缚，迎来晋升或转变的机遇。若飞得轻盈高远，则诸事顺遂，鸿图大展；若飞得吃力，则提示当前仍有阻碍，需耐心等待。',
    freudian: '弗洛伊德认为飞翔梦与性欲望的升华密切相关，是力比多能量向更高精神层面转化的象征。荣格则将飞翔解读为对超越自我局限的渴望，是人格成长与个体化进程的体现。从现代心理学角度，飞翔梦常见于自我效能感高涨时期，反映了潜意识中对自由与掌控感的强烈渴求。',
  },
  {
    keyword: '坠落',
    traditional: '梦见坠落，往往是身心焦虑的外化信号。周公解梦认为，坠落之梦提示近期有失误之虞，宜谨言慎行，防止因疏忽大意造成损失。若坠入深渊，需警惕事业或感情出现重大危机；若有所依托而止，则难题将有人相助化解。',
    freudian: '弗洛伊德将坠落梦解释为超我对本我的压制——个体在高速追求目标时，内心的恐惧与不安感形成对抗，通过坠落意象表达"我不配得到这些"的潜意识信念。现代认知心理学研究表明，坠落梦多出现于睡眠入睡阶段的肌肉痉挛（催眠抽搐），但其内容折射了个体对失控的深层恐惧。',
  },
  {
    keyword: '蛇',
    traditional: '梦蛇是最具争议的梦境之一。周公解梦认为：梦见大蛇，预示贵人来助，事业有贵人相助；被蛇咬则须防小人中伤；蛇入怀则是怀孕或有意外之财的征兆；白蛇象征吉祥智慧；黑蛇提示阴险小人近侧，须防范。',
    freudian: '弗洛伊德在《梦的解析》中明确指出，蛇是最典型的男性生殖器符号，梦见蛇往往与性压抑、性诱惑或被压制的欲望有关。荣格则认为蛇代表集体无意识中的原始力量与蜕变（蛇蜕皮象征死亡与重生），是智慧与变化的原型意象。从现代心理学看，梦蛇也可能反映对某人或某情况的本能警觉与不信任。',
  },
  {
    keyword: '水',
    traditional: '水在周公解梦中象征财运与情感。梦见清澈流水，财运亨通，情感顺遂；梦见浑浊污水，则需警惕财务纠纷和小人暗算；水漫房间，预示家中将有喜事（如添丁进口）；溺水之梦，提示近期遭遇困境，宜寻求外援；跨越大水，意味着克服重大障碍，前途光明。',
    freudian: '弗洛伊德认为水是母亲与出生的象征，代表生命本源与无意识。梦见水往往与情感需求、对母性安慰的渴望以及对生命起源的潜意识回归有关。荣格则视水为集体无意识本身——深不可测、充满生命力的原始能量。现代心理学研究发现，梦见洪水或溺水与个人感到被情绪"淹没"、无法掌控生活密切相关。',
  },
  {
    keyword: '火',
    traditional: '火在周公解梦中是双刃之象。梦见旺盛的火焰，预示事业蒸蒸日上，财运亨通；家中失火，反而可能是家业兴旺的吉兆，但也提示注意防火安全；被火烧伤，象征近期有口舌是非或遭人嫉恨；火光熄灭，则预示事业走向低谷，需振作精神。',
    freudian: '弗洛伊德将火视为性冲动与攻击性欲望的象征，与力比多的燃烧状态对应，梦见火往往折射出被压制的激情与愤怒。荣格则将火解读为"灵魂转化"的原型——炼金术中火可将物质提纯，心理层面则象征通过苦难实现精神升华。现代创伤研究发现，亲历火灾后的幸存者梦境中火的意象与PTSD的情绪再体验密切相关。',
  },
  {
    keyword: '牙齿',
    traditional: '梦见牙齿脱落是最为普遍的梦境之一，在中国传统文化中寓意深刻。周公解梦认为牙齿象征亲人与根基：牙齿脱落预示家中长辈可能健康有虞，宜关注家人身体；若梦中牙齿纷纷脱落，则需警惕家庭变故；牙齿松动但未脱落，提示家庭关系趋于不稳定，宜加强沟通。',
    freudian: '弗洛伊德认为牙齿脱落梦与阉割焦虑直接相关，是男性对权力与性能力丧失的潜意识恐惧的象征表达。对女性而言，则更多与外貌焦虑和社会形象压力相关。现代心理学研究表明，牙齿梦在高压力、重要社交考验前发生率显著提高，直接反映了个体对"展示自我、被人评判"的深层恐惧。磨牙习惯者也更频繁报告此类梦境。',
  },
  {
    keyword: '老虎',
    traditional: '老虎在中国文化中是威权、力量与煞气的象征。梦见老虎：若虎向你扑来而你不惧，预示有强力贵人或上级助力，大事可成；被老虎追赶且逃跑，则说明你正承受巨大压力，有权威人士给你带来威胁感；梦见驯服老虎，象征你即将克服强大的对手，成就一番事业。',
    freudian: '弗洛伊德视老虎为强大、不可控的本我冲动（如性欲、攻击欲）的象征。梦中被老虎追赶，意味着个体逃避自身强烈的欲望或愤怒，超我（道德约束）在压制本我的过程中制造了恐惧与逃跑的场景。荣格则将老虎视为"阴影"原型——被压抑的力量与黑暗面的化身，梦见老虎是潜意识要求正视并整合这部分力量的信号。',
  },
  {
    keyword: '死亡',
    traditional: '梦见死亡在周公解梦中往往是吉兆，而非凶兆。梦见自己死亡，预示健康长寿，或事业即将迎来重大转变与新生；梦见亲人去世，反而是此人将有好运降临的征兆；梦见陌生人死亡，提示身边将有变化；梦见复活，则是否极泰来、万象更新的吉象。',
    freudian: '弗洛伊德在死亡本能（死亡驱力，Thanatos）理论中指出，人类内心深处同时存在生命冲动（Eros）与死亡冲动（Thanatos）。梦见死亡往往是死亡驱力在梦境中的投射，也可能折射出对某人或某段关系的"消除"欲望（愿望满足）。现代心理学将死亡梦解读为"终结"的象征——旧我的消亡与新我的诞生，常见于人生重大转折点前后。',
  },
  {
    keyword: '考试',
    traditional: '梦见考试是现代人最常见的焦虑梦之一。周公解梦认为：梦见考试顺利，预示近期工作或学习会得到认可和奖励；梦见考试失败，则提示需要认真准备，查缺补漏；梦见考试迟到，暗示当前生活节奏过快，某些重要事务有被忽略的风险。',
    freudian: '弗洛伊德对考试梦有专门论述：他发现人们常梦见自己在已经通过的考试中失败，这体现了"预期焦虑"——人面临新挑战时，潜意识调用过去成功应对考验的记忆来平衡当前的焦虑。考试梦本质上是超我对自我能力的审判，代表个体对自身是否"够格""达标"的深层质疑，与自我价值感密切相关。',
  },
  {
    keyword: '追赶',
    traditional: '梦见被追赶，提示现实中有令你感到威胁或压力的人或事。周公解梦认为：被人追赶且被捉住，预示近期有麻烦难以逃脱；成功逃脱追赶，则说明你有能力化解当前的危机；追赶他人且追上，象征你将在竞争中取得胜利。',
    freudian: '被追赶是最典型的焦虑梦，弗洛伊德将其解读为本我与超我之间张力的戏剧化呈现——个体试图逃避被压制的欲望、恐惧或羞耻感，但它们以追捕者的形象穷追不舍。追捕者的身份往往暗示其象征对象：若是陌生人，代表泛化焦虑；若是特定人物，则指向与该人相关的心理冲突。',
  },
  {
    keyword: '鱼',
    traditional: '鱼在中国文化中谐音"余"，象征富足与吉祥。周公解梦：梦见鱼在清水中游动，预示财运亨通，生活富足；捕到大鱼，象征将获得重大机遇或意外之财；鱼跃龙门，是功名利禄的大吉之兆；梦见鱼死，则需注意财务安全，防止损失；吃鱼之梦，象征健康长寿，子孙繁盛。',
    freudian: '弗洛伊德认为鱼是常见的性象征，与生殖能力和水（母性）原型密切相关。捕鱼梦可能折射征服、获取的欲望；鱼游动的姿态与水中的自由则象征本我的流动能量未受压制。荣格视鱼为集体无意识深处的智慧（基督教中鱼亦为灵性象征），梦见鱼可能是潜意识试图传递某种深层智慧或灵性召唤。',
  },
  {
    keyword: '龙',
    traditional: '龙是中华文化中最尊贵的神兽，梦龙历来被视为大吉之兆。周公解梦：梦见龙飞升天，预示事业腾飞，仕途顺遂，位极人臣；梦见乘龙，象征贵人相助，平步青云；女性梦龙，古时认为是将生贵子或有大贵之人降临家门的征兆；梦见龙入水，是财运滚滚而来的吉象。',
    freudian: '弗洛伊德将龙这类神话巨兽解读为强大的父亲形象或权威象征，亦与男性力量和性能量相关。从荣格的原型理论看，龙是集体无意识中"力量"与"神圣"的原型意象，代表需要被英雄（自我）正视和整合的强大力量。在东西方文化中龙的象征存在差异——东方龙象征祥瑞，西方龙象征邪恶，这种差异本身就是文化集体无意识的体现。',
  },
  {
    keyword: '金钱',
    traditional: '周公解梦认为，梦见金钱的吉凶需结合情境判断：梦见得到大量钱财，预示近期可能有意外损失（物极必反）；梦见丢失钱财，反而可能预示将有财运降临；梦见数钱，是谨慎理财、收入稳定的吉兆；梦见给人钱，象征将有贵人相助，或因慷慨获得好报。',
    freudian: '弗洛伊德在《性学三论》中提出著名的"金钱=粪便"象征理论（肛欲期滞留的象征化），认为对金钱的过度执著与肛欲期的控制欲有关。然而现代心理学对此持保留态度，更倾向于将金钱梦解读为个体对安全感、价值感与社会地位的焦虑——失去金钱意味着失去掌控，获得金钱则象征自我价值的确认。',
  },
  {
    keyword: '房子',
    traditional: '房子在周公解梦中象征身体、家庭与自我的庇护所。梦见住进新房，预示将有喜事临门，事业或家庭将迎来新的开始；梦见房屋倒塌，则提示身体健康需要关注，或家庭将有变故；梦见装修改造，象征个人形象或生活状态将发生积极变化；梦见房子漏水，需警惕财务漏洞与隐性损失。',
    freudian: '弗洛伊德和荣格都将房子视为"自我"的典型象征，房子的各个部分对应不同的心理层面：地下室象征无意识；客厅象征社交自我（人格面具）；卧室象征私密的内心世界；阁楼象征高级思维与精神追求。梦见房子破旧意味着自我整合不足；探索房子的未知房间，则是发现潜意识中未被整合的心理内容的隐喻。',
  },
  {
    keyword: '路',
    traditional: '梦见走路、道路，是人生方向与前途的象征。周公解梦：梦见走在宽阔平坦的大道，预示前途光明，诸事顺遂；道路崎岖弯曲，提示近期事业有阻，需做好心理准备；迷失在岔路口，象征当前面临重大抉择，内心犹豫不决；道路被阻断，提示计划中有阻碍，需另辟蹊径。',
    freudian: '弗洛伊德认为道路是人生旅程与性行为过程的隐喻，一路走去象征进行中的愿望满足。荣格则将路解读为个体化进程——人生旅途中自我整合的方向与选择。从现代心理学看，梦见道路的状态直接反映了个体对当前人生方向的感受：平坦意味着顺遂，迷路意味着迷失感，断路则反映感到前途被封堵的焦虑。',
  },
  {
    keyword: '桥',
    traditional: '桥是过渡与连接的象征。周公解梦认为：梦见顺利走过桥，预示将克服当前的困难，进入新的阶段；桥断裂或摇摇欲坠，提示计划受阻，合作关系可能出现裂缝；桥下水流湍急，象征当前转变期充满挑战，需小心谨慎；梦见建造桥梁，预示将成为沟通双方的重要中间人。',
    freudian: '荣格尤其强调桥的原型意义——桥梁连接意识与无意识，是心理整合的象征。梦见过桥代表个体正处于从一个心理状态过渡到另一个心理状态的关键期，是深层心理变化的信号。弗洛伊德则更关注桥下流动的水（象征无意识流动），以及过桥的成功与否（对应愿望满足程度）。',
  },
  {
    keyword: '孩子',
    traditional: '梦见孩子，在周公解梦中是新生与希望的象征。梦见可爱健康的孩子，预示家庭将添新丁或事业将有新的开始；梦见孩子哭泣，可能提示家中将有烦恼或琐事缠身；梦见孩子玩耍嬉戏，象征生活充满活力与童趣，是轻松愉快的好兆头；养育孩子的梦，提示近期需要照顾某个重要项目。',
    freudian: '弗洛伊德认为梦见孩子（尤其是梦见自己回到童年）是对童年时期未满足愿望的回溯，以及对内心最原始需求的触碰。荣格则视"神圣小孩"（Divine Child）为整合原型——代表潜意识中尚未开发的潜力与未来可能性。梦中孩子往往是梦者自己内心那个"内在小孩"的投影，承载着被成年规则压抑的真实情感与创造力。',
  },
  {
    keyword: '父母',
    traditional: '梦见父母，是中国人最看重的梦境之一，周公解梦将其与家族命运紧密相连。梦见父母健康欢笑，是大吉之兆，预示家庭和睦，诸事顺遂；梦见父母生病，提示需关注父母健康，或家庭将有变故；梦见与父母争吵，则是内心压力外化，提示需要与家人加强沟通；梦见已故父母，是思念之梦，也提示宜思考人生方向。',
    freudian: '弗洛伊德将父母梦与恋母/恋父情结（俄狄浦斯情结）紧密相连，认为父母是我们最重要的心理客体（Object）。梦见父母反映了个体与父母形成的依恋模式——依赖、权威崇拜、反叛或未解决的冲突。荣格则视父母为"父亲原型"和"母亲原型"的人格化——父亲象征秩序与精神，母亲象征滋养与无意识，梦见父母是与这些原型力量对话的机会。',
  },
  {
    keyword: '山',
    traditional: '山是坚定、高远与挑战的象征。周公解梦：梦见登上山顶，预示经过努力终将成功，事业有大成就；登山途中跌倒，提示前进过程中有挫折，需调整策略；梦见被困山中，象征当前处境艰难，出路不明；远望山峰，象征人生目标明确但仍在前进途中；梦见山崩，提示将有重大变故，宜谨慎应对。',
    freudian: '弗洛伊德将登山解读为带有性意味的征服行为，山的形状亦可作为阳具的象征。荣格则将山视为"自性"（Self）的象征——山顶代表自我实现的最高境界，登山即是个体化进程的隐喻，每一步向上都是超越自身局限的努力。从存在主义心理学视角，山代表人生中必须独自面对的重大挑战，梦见登山反映了个体对意义与超越的渴望。',
  },
  {
    keyword: '大海',
    traditional: '海洋在周公解梦中象征财富、机遇与情感的深度。梦见碧波荡漾的大海，预示财运广进，情感丰沛；乘船顺利渡海，象征将克服大困难，事业一帆风顺；被海浪吞没，提示近期情绪波动较大，或有感情危机；站在海边遥望，预示将有远行或重大决定即将做出；梦见沧海变桑田，是人生重大转变的预示。',
    freudian: '弗洛伊德将大海视为母亲与无意识的象征，宏大浩渺的海洋代表潜意识的深不可测。"海洋感"（Oceanic Feeling）一词正是弗洛伊德借用的，描述婴儿期自我与外界尚未分离的原始体验——梦见大海常与对融合、安全感的原始渴望相关。荣格则将海洋视为集体无意识的完整意象，深海中的生物代表人类共有的原型力量。',
  },
  {
    keyword: '猫',
    traditional: '猫在中国传统文化中是神秘、灵性与招财的象征。周公解梦：梦见白猫，预示将遇到贵人，或有意外好运；梦见黑猫，提示身边可能有不诚实之人，需谨慎分辨；猫抓人，暗示有口舌是非将至；猫带来死鼠，是破财去病、驱邪化煞的吉兆；梦见养猫，预示家庭将有新成员或需要承担新责任。',
    freudian: '弗洛伊德认为猫（尤其是女性梦见猫）往往与女性气质、神秘感和性诱惑的象征相关。猫的独立性与不可控性，在心理层面代表个体对自身某种"不受驯服"的特质的感受。荣格则将猫视为"阿尼玛"（Anima，男性心理中的女性面）或女性潜意识的投射——猫的神秘、直觉与感性特质，正是被理性压制的感性自我的化身。',
  },
  {
    keyword: '狗',
    traditional: '狗是忠诚与本能的象征。周公解梦：梦见狗摇尾示好，预示将有忠实朋友相助，人际关系顺畅；被狗追赶或咬伤，需防范友人背叛或合作方违约；狗在门口吠叫，提示需警惕家宅安全或有不速之客；梦见喂狗，象征你将对他人产生正面影响；狗死去，则提示一段忠实的合作或友谊将告一段落。',
    freudian: '弗洛伊德将狗视为忠实但被驯化的本能力量——狗象征个体已被社会化规范所驯服的冲动（与野性动物相比，狗更像是被超我接受的本我的驯化形象）。被狗攻击，可能意味着自身被压制的攻击性或忠诚受到了威胁。荣格则认为狗是"本能向导"的原型——就像神话中引导亡魂渡冥河的神犬，梦中的狗往往是引领个体进入无意识深处的心理向导。',
  },
  {
    keyword: '鸟',
    traditional: '鸟在周公解梦中多为吉祥之兆。喜鹊入梦，是喜事临门的报喜之兆；凤凰入梦，象征大贵大吉，事业腾飞；乌鸦入梦，提示近期可能有坏消息，宜谨慎；鸽子入梦，象征和平、婚姻美满；老鹰入梦，象征雄才大略，将有大事成就；群鸟飞翔，预示志同道合之人汇聚，合作共赢。',
    freudian: '弗洛伊德认为鸟（尤其是飞鸟）是性能量升华的象征——鸟的飞翔代表力比多向精神层面的提升。不同的鸟有不同的象征：猛禽（鹰、隼）代表阳刚力量与攻击本能；小鸟代表脆弱与依赖的部分自我。荣格则视鸟为精神与灵魂的原型象征（许多文化中鸟是灵魂的使者），梦中飞鸟是自由精神与超脱本能的渴望的体现。',
  },
  {
    keyword: '花',
    traditional: '花象征美好、青春与感情。周公解梦：梦见花开满园，预示家庭幸福，感情顺遂，前途似锦；鲜花凋零，则提示感情或事业将遇挫折，宜珍惜当下；玫瑰入梦，是爱情来临的征兆；牡丹入梦，象征富贵荣华；菊花入梦，在传统中有思念故人之意；采花之梦，预示将获得美好的机遇与爱情。',
    freudian: '弗洛伊德认为花是女性生殖器的典型象征，梦见花朵与性意识、生育能力以及女性气质的接受程度密切相关。荣格则视花为"自性"（Self）圆满的象征——曼陀罗（Mandala，圆形的神圣图案）常以花朵形式呈现，代表心理整合的圆满状态。现代心理学认为，梦见盛花通常与正向情感状态、幸福感和创造力处于高峰期相关。',
  },
  {
    keyword: '太阳',
    traditional: '太阳是六十四卦乾卦的现实对应，是光明、生命与权威的象征。周公解梦：梦见旭日东升，是大吉之兆，预示事业腾飞，诸事顺遂；梦见太阳照耀全身，象征健康长寿，事事如意；太阳西沉，则提示某段时期或某段关系即将结束；梦见两个太阳，是极为罕见的大贵大吉之兆，预示非凡成就。',
    freudian: '弗洛伊德将太阳视为强大父亲形象的象征，代表权威、超我与照耀一切的理性意识。荣格则认为太阳是"意识自我"（Ego）的原型——意识是心灵中的太阳，而无意识则是黑夜。直视太阳或太阳受损（日食）的梦，可能反映个体自我意识受到挑战，或与权威人物产生冲突的深层体验。许多文化中太阳亦是神性的象征，梦见太阳也可能是精神追求的显现。',
  },
  {
    keyword: '下雨',
    traditional: '雨在周公解梦中象征滋润与转变。梦见温和细雨，是财运与好运降临的吉兆，提示付出将有回报；暴风雨则预示近期将有大的变故或压力袭来，宜做好心理准备；淋雨而不冷，象征将获得意外之财；被雨淋湿狼狈，提示近期可能有不顺或失误；梦见站在雨中久等，象征在等待期盼中度过某段时光。',
    freudian: '弗洛伊德认为雨是精液与性结合的象征，在"天象"层面对应男性性行为的意象。现代心理学则更倾向于将雨解读为情绪的释放与净化——梦见下雨往往与积压的情感终于得到宣泄的感受相关（"泪水"与"雨水"在无意识中的联结）。暴雨象征情绪失控，细雨象征温和的情绪流动，彩虹出现则代表情绪释放后的平静与希望。',
  },
  {
    keyword: '车',
    traditional: '车在现代周公解梦中象征人生方向与行动力。梦见驾车顺畅，预示事业顺利，目标明确，行动力强；刹车失灵，提示当前行事过于冲动，需放慢脚步、谨慎行事；发生车祸，是对当前生活节奏过快的强烈警示，宜及时调整；坐在副驾，象征你处于被人主导的状态，可能需要主动掌握更多控制权。',
    freudian: '弗洛伊德将驾车梦与控制欲和性行为的节律相关联——踩油门与刹车象征冲动与抑制的交替。荣格则将车视为"个体化"进程中自我方向感的象征：驾车代表个体对自身生命方向的主导，迷路或失控则象征自我整合过程中的迷失。现代神经科学研究表明，频繁做关于驾车失控的梦，与前额叶皮质对情绪调节能力下降有相关性。',
  },
  {
    keyword: '飞机',
    traditional: '飞机在现代梦境中是高远目标、快速发展与远行的象征。周公解梦认为：梦见乘飞机顺利起飞，预示事业将有重大突破，目标实现指日可待；飞机延误，提示当前计划遇阻，需耐心等待时机；飞机坠落，是对重大决策过于冒进的强烈警告，宜三思而后行；在机舱内向外俯视大地，象征开阔的视野和宏观思考能力。',
    freudian: '弗洛伊德时代飞机尚未普及，但现代精神分析将飞机梦视为飞翔梦（性欲升华）的现代化版本，同时也包含了对现代科技与人类能力扩展的集体无意识处理。飞机坠落的梦是当代最常见的焦虑梦之一，往往与对快速变化的现代生活失控感相关。坐飞机亦象征"委托他人驾驶你的人生"，与控制欲和对权威的信任问题相关。',
  },
  {
    keyword: '血',
    traditional: '血在周公解梦中的吉凶需依情境判断，但多数情况下是活力与重要性的象征。梦见自己流血但不疼，往往是财运或健康改善的吉兆；为他人包扎止血，象征将为他人提供重要帮助，并因此获益；衣服染血，提示近期需注意健康；梦见大量鲜血而不恐惧，在某些传统解读中是财富大量积累的征兆。',
    freudian: '弗洛伊德将血视为生命能量、性与攻击性的象征。梦中流血与身体损伤有关，可能折射对身体受损或阉割的潜意识恐惧，也与月经等女性生理周期的象征意义相关。荣格则将血视为"灵魂的液体"——许多文化中血是生命的本质，梦见血可能是潜意识提示某件事对个体有着深刻的情感价值，不可忽视。',
  },
  {
    keyword: '婚礼',
    traditional: '婚礼之梦在周公解梦中多为喜庆之兆。梦见参加他人婚礼，预示自己近期亦有喜事临门；梦见自己结婚，对未婚者是缘分将至的吉兆，对已婚者则象征感情升温、家庭更加美满；婚礼出现变故，提示感情路上需多加沟通，防止误解积累；梦见盛大婚礼宾客云集，预示社交运势极旺。',
    freudian: '弗洛伊德认为婚礼梦是性成熟与对亲密关系渴望的直接表达，与深层的配对本能（mate selection drive）相关。婚礼梦也可能是对现有关系状态的评估——美好的婚礼梦反映满意的关系状态，出现问题的婚礼梦则可能折射对关系现状的潜在不满或焦虑。荣格则视婚礼为"神圣婚姻"（Hieros Gamos）原型——意识与无意识的结合，是心理整合完成的象征。',
  },
  {
    keyword: '迷路',
    traditional: '迷路之梦是对人生方向迷失的直接提示。周公解梦认为：在荒野中迷路，提示当前面临重大抉择，内心困惑，宜向有经验者寻求建议；在城市中迷路，象征在繁杂的社会关系中失去了自我方向，宜简化生活；梦中找到出路，预示困惑将在近期得到解答；始终找不到路，提示需要认真规划，重新确立人生目标。',
    freudian: '弗洛伊德将迷路梦解读为性焦虑的空间化表达——在性行为或欲望满足的象征旅途中找不到方向，反映了对亲密关系的不确定感。荣格则从个体化进程的视角解读迷路：人在人生旅途中迷失，往往是因为过分遵从外界期待而失去了自我。迷路梦是潜意识发出的"找回真我"的召唤，提示个体需要重新连接内心真实的声音与方向。',
  },
  {
    keyword: '游泳',
    traditional: '游泳在周公解梦中象征在人生之流中的应对能力。梦见在清澈水中畅游，预示身体健康，财运亨通，处世游刃有余；在浑浊的水中费力游泳，提示当前环境复杂，需借助他人帮助；溺水挣扎，是当前处境危险的强烈信号，宜寻求援助；教他人游泳，象征将担任指导他人的重要角色。',
    freudian: '弗洛伊德将游泳视为在无意识（水）中的探索，象征个体对自身情感深度的接触能力——游泳技术好象征情商高、善于处理情感；溺水则反映被情感淹没、情绪调节能力不足。荣格认为在水中游泳是个体主动进入无意识领域的勇敢行为——既有下沉的危险（迷失于无意识），也有发现宝藏的可能（获得深层智慧）。',
  },
  {
    keyword: '镜子',
    traditional: '镜子在周公解梦中是自我认知与真相的象征。梦见镜中形象清晰，预示自我认知准确，处世清明，近期诸事明朗；镜中形象模糊，提示近期可能遇到欺骗，或对某人某事认知有偏差；镜子破碎，是家庭将有变故或重要关系破裂的警示；从镜子中看到他人，提示你正在他人身上寻找自己的影子。',
    freudian: '弗洛伊德将镜子梦与自恋（Narcissism）联系——凝视镜中之我，是力比多向自身的回流，折射个体对自我形象的执著与自我价值的渴求。荣格则视镜子为"反思与自知"的象征，镜中的形象可能不只是镜者本人，而是"影子"（Shadow）——那个被压抑的、自己不愿承认的自我面向，在梦中以镜像的方式浮现，要求被正视与整合。',
  },
  {
    keyword: '彩虹',
    traditional: '彩虹在各文化中都是最美好的吉兆之一。周公解梦：梦见天空出现彩虹，预示困难时期即将过去，好运即将来临；彩虹出现在家宅上空，象征家庭将迎来喜事，财运大旺；在彩虹下行走，预示将获得名誉与成就；彩虹消散，则是提示好运虽在，但需把握时机，转瞬即逝。',
    freudian: '弗洛伊德较少专门讨论彩虹，但从意象分析角度，彩虹作为光（意识）穿透水（无意识/情感）后折射出的多彩光谱，象征理性与情感融合后产生的多元自我表达。荣格则将彩虹视为天与地之间的桥梁——意识与无意识、人间与神性的连接——是心理整合达到某种完整性的象征。许多传统文化将彩虹视为神的承诺，反映了集体无意识对希望与庇护的永恒渴望。',
  },
  {
    keyword: '学校',
    traditional: '梦见学校在现代梦境解析中象征学习、评估与成长。周公解梦认为：回到熟悉的学校，是对过去美好时光的留恋，也预示将重拾某种技能；在学校迷路，提示当前对某个领域感到陌生或不适应；梦见在学校受到表扬，预示近期工作或学习将得到认可；梦见老师，象征生命中对你有影响的智者即将出现或已在身边。',
    freudian: '弗洛伊德将学校梦与超我的审判功能相关联——学校是社会规范与评价体系的典型场所，梦见考试失败或被老师批评，是超我对自我的严厉批判的投射。荣格则视学校为"精神发展道场"，老师是"智慧老人"（Wise Old Man/Woman）原型的化身——内在导师的出现，意味着潜意识已准备好传授重要的人生功课，梦者需要静下心来倾听。',
  },
]

const query = ref('')
const expandedKey = ref<string | null>(null)
const activeTab = ref<'traditional' | 'freudian'>('traditional')

const filtered = computed(() => {
  const q = query.value.trim()
  if (!q) return DREAMS
  return DREAMS.filter(d => d.keyword.includes(q) || d.traditional.includes(q) || d.freudian.includes(q))
})

function toggle(keyword: string) {
  if (expandedKey.value === keyword) {
    expandedKey.value = null
  } else {
    expandedKey.value = keyword
    activeTab.value = 'traditional'
  }
}
</script>

<template>
  <div class="h-full flex flex-col bg-white text-gray-900">
    <header class="flex-none px-4 py-3 border-b border-gray-200 safe-top flex items-center gap-2">
      <span>🌙</span>
      <h1 class="text-lg font-semibold">周公解梦</h1>
    </header>

    <!-- AI 自定义解梦 -->
    <div class="flex-none border-b border-gray-200 px-4 py-4 bg-gradient-to-b from-purple-100/30 to-transparent">
      <p class="text-xs text-purple-700 font-medium mb-2">✨ AI 自定义解梦</p>
      <textarea
        v-model="customDesc"
        :disabled="customLoading"
        placeholder="描述你的梦境，越详细越准确……例如：梦见老板的别墅被封了，我们偷偷进去，里面全是课桌椅，然后打电话给大伯把事情处理好了"
        rows="3"
        class="w-full bg-gray-50 rounded-xl px-3 py-2.5 text-sm text-gray-900 placeholder-gray-600 outline-none focus:ring-1 focus:ring-purple-500 resize-none disabled:opacity-50"
      />
      <div class="flex items-center gap-2 mt-2">
        <button
          :disabled="customLoading || customDesc.trim().length < 10"
          class="flex-1 py-2 rounded-xl bg-purple-600 text-white text-sm font-medium disabled:opacity-40 active:opacity-80 flex items-center justify-center gap-1.5"
          @click="interpretDream"
        >
          <span v-if="customLoading" class="animate-spin">⟳</span>
          <span>{{ customLoading ? '解梦中…' : '开始解梦' }}</span>
        </button>
        <button v-if="customResult || customDesc" class="px-3 py-2 rounded-xl bg-gray-100 text-gray-600 text-sm" @click="clearCustom">清空</button>
      </div>
      <p v-if="customError" class="text-xs text-red-400 mt-2">{{ customError }}</p>

      <!-- Result -->
      <div v-if="customResult" class="mt-3">
        <div class="flex gap-2 mb-2">
          <button
            :class="['px-3 py-1 rounded-full text-xs font-medium transition-colors', customTab === 'traditional' ? 'bg-yellow-500/20 text-yellow-700' : 'bg-gray-100 text-gray-600']"
            @click="customTab = 'traditional'"
          >周公解梦</button>
          <button
            :class="['px-3 py-1 rounded-full text-xs font-medium transition-colors', customTab === 'freudian' ? 'bg-purple-500/20 text-purple-700' : 'bg-gray-100 text-gray-600']"
            @click="customTab = 'freudian'"
          >弗洛伊德解析</button>
        </div>
        <div v-if="customTab === 'traditional'" class="bg-gray-50 rounded-xl p-3">
          <p class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ customResult.traditional }}</p>
        </div>
        <div v-else class="bg-gray-50 rounded-xl p-3 border border-purple-500/20">
          <p class="text-xs text-purple-700 mb-1.5 font-medium">心理学解析</p>
          <p class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ customResult.freudian }}</p>
        </div>
      </div>
    </div>

    <!-- Search -->
    <div class="flex-none px-4 py-3 border-b border-gray-200">
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">🔍</span>
        <input
          v-model="query"
          type="text"
          placeholder="搜索梦境关键词，如：蛇、飞翔、火…"
          class="w-full bg-gray-50 rounded-xl pl-9 pr-4 py-2.5 text-sm text-gray-900 placeholder-gray-500 outline-none focus:ring-1 focus:ring-primary"
        />
      </div>
    </div>

    <!-- Results -->
    <div class="flex-1 overflow-y-auto">
      <div v-if="!filtered.length" class="flex flex-col items-center justify-center h-40 text-gray-500 gap-2">
        <span class="text-2xl">🌙</span>
        <p class="text-sm">未找到相关解释</p>
      </div>
      <div v-else class="divide-y divide-gray-200/50">
        <div v-for="entry in filtered" :key="entry.keyword">
          <!-- Row header -->
          <button
            class="w-full px-4 py-4 flex justify-between items-center text-left active:bg-gray-50"
            @click="toggle(entry.keyword)"
          >
            <span class="font-semibold text-yellow-700">梦见{{ entry.keyword }}</span>
            <span class="text-gray-500 text-lg transition-transform" :class="expandedKey === entry.keyword ? 'rotate-180' : ''">⌄</span>
          </button>

          <!-- Expanded content -->
          <div v-if="expandedKey === entry.keyword" class="px-4 pb-4">
            <!-- Tabs -->
            <div class="flex gap-2 mb-3">
              <button
                :class="['px-3 py-1 rounded-full text-xs font-medium transition-colors', activeTab === 'traditional' ? 'bg-yellow-500/20 text-yellow-700' : 'bg-gray-100 text-gray-600']"
                @click="activeTab = 'traditional'"
              >周公解梦</button>
              <button
                :class="['px-3 py-1 rounded-full text-xs font-medium transition-colors', activeTab === 'freudian' ? 'bg-purple-500/20 text-purple-700' : 'bg-gray-100 text-gray-600']"
                @click="activeTab = 'freudian'"
              >弗洛伊德解析</button>
            </div>

            <div v-if="activeTab === 'traditional'" class="bg-gray-50 rounded-xl p-3">
              <p class="text-sm text-gray-800 leading-relaxed">{{ entry.traditional }}</p>
            </div>
            <div v-else class="bg-gray-50 rounded-xl p-3 border border-purple-500/20">
              <p class="text-xs text-purple-700 mb-1.5 font-medium">心理学解析</p>
              <p class="text-sm text-gray-800 leading-relaxed">{{ entry.freudian }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
