; Question of the Day native HTTP service.
; The request lifecycle and socket handling are supplied by rwasa/HeavyThing.
include '../reference_asm/ht_defaults.inc'
include '../reference_asm/ht.inc'
include '../reference_asm/rwasa/arguments.inc'
include '../reference_asm/rwasa/worker.inc'
include '../reference_asm/rwasa/master.inc'

; rwasa calls this hook with:
;   rsi = parsed request URL
;   rdx = request headers
; A mimelike object is returned to produce the response.
falign
asmcall:
	prolog asmcall
	push rbx r12 r13 r14 r15

	; Read quotes.txt through HeavyThing's file wrapper.  A missing or
	; zero-length file returns a null buffer, so both cases use the fallback.
	mov rdi, .quotes_path
	call file$to_buffer_cstr
	mov rbx, rax
	test rbx, rbx
	jz .fallback

	; Count non-empty lines before choosing one.  has_more_lines with ESI=1
	; consumes leading blank lines, which is exactly the database policy.
	xor r12d, r12d
	mov rdi, rbx
	mov esi, 1
.count_lines:
	call buffer$has_more_lines
	test eax, eax
	jz .count_done
	mov rdi, rbx
	call buffer$nextline
	test qword [rax], qword -1
	jz .free_count_line
	inc r12d
.free_count_line:
	mov rdi, rax
	call heap$free
	jmp .count_lines

.count_done:
	test r12d, r12d
	jz .empty_file

	; RDRAND supplies the selection entropy.  Division gives an unbiased
	; index in [0,count); RDX must be cleared because DIV uses RDX:RAX.
.random_retry:
	rdrand eax
	jnc .random_retry
	xor edx, edx
	div r12d
	mov r14d, edx

	; Reload the consumed buffer and walk to the selected non-empty line.
	mov rdi, rbx
	call buffer$destroy
	mov rdi, .quotes_path
	call file$to_buffer_cstr
	mov rbx, rax
	mov rdi, rbx
	mov esi, 1
.select_line:
	call buffer$has_more_lines
	test eax, eax
	jz .fallback
	mov rdi, rbx
	call buffer$nextline
	cmp qword [rax], 0
	je .discard_line
	test r14d, r14d
	jz .selected
	dec r14d
.discard_line:
	mov rdi, rax
	call heap$free
	jmp .select_line

.selected:
	mov r13, rax
	mov rdi, rbx
	call buffer$destroy
	jmp .make_page

.empty_file:
	mov rdi, rbx
	call buffer$destroy
.fallback:
	; Copying the static string gives the response a uniformly owned string,
	; allowing the cleanup path to free it without special cases.
	mov rdi, .fallback_quote
	call string$copy
	mov r13, rax

.make_page:
	; Match the PHP application's public shell and its UTF-8 content type.
	call buffer$new
	mov r15, rax
	mov rdi, r15
	mov rsi, .html_prefix
	call buffer$append_string
	mov rdi, r15
	mov rsi, r13
	call buffer$append_string
	mov rdi, r15
	mov rsi, .html_suffix
	call buffer$append_string

	call mimelike$new
	mov r12, rax
	mov rdi, r12
	mov rsi, .http_preface
	call mimelike$setpreface
	mov rdi, r12
	mov rsi, mimelike$contenttype
	mov rdx, mimelike$texthtml
	call mimelike$setheader
	mov rdi, r12
	mov rsi, [r15+buffer_itself_ofs]
	mov rdx, [r15+buffer_length_ofs]
	call mimelike$setbody

	mov rdi, r15
	call buffer$destroy
	mov rdi, r13
	call heap$free
	mov rax, r12
	pop r15 r14 r13 r12 rbx
	epilog

cleartext .quotes_path, 'quotes.txt', 0
cleartext .fallback_quote, 'Assembly is eternal.'
cleartext .html_prefix, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Question of the Day</title></head><body><main><p>'
cleartext .html_suffix, '</p></main></body></html>'
cleartext .http_preface, 'HTTP/1.1 200 OK'
cleartext .route, '/'

public _start
falign
_start:
	call ht$init
	call arguments

	; The legacy public router only serves the homepage here.  Override
	; rwasa's demonstration suffix so exactly "/" reaches this hook.
	mov rdi, [funcmatch]
	call heap$free
	mov rdi, .route
	call string$copy
	mov [funcmatch], rax

	mov rdi, [configs]
	mov rsi, .hookthemall
	call list$foreach
	jmp masterthread

falign
.hookthemall:
	mov rsi, [funcmatch]
	mov rdx, asmcall
	call webservercfg$function_map
	ret

include '../reference_asm/ht_data.inc'
