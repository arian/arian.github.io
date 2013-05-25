title: Dynamic disassembling instructions with ptrace and udis86 for timing analysis
date: 2013-05-25 18:40:54
tags: [ptrace, udis86]
comments: yes
---

For a University assignment for a course called _Real-Time Systems_ we had to
implement a prototype for timing analysis by tracing instructions.

The idea is that when executing a program, ptrace controls the execution and
stops after each instruction. After this the actual instruction in the memory
can be fetched. Knowing which instruction is executed you could associate this
with a certain time, add all times together for each instruction and you could
dynamically determine how long a program would run: profit!

I made a prototype that can be viewed on [GitHub](https://github.com/arian/ptrace).

ptrace
------

ptrace is a tool that can control processes. It is usually used in debuggers,
to trace calls, stop at breakpoints, change variables and such.

With `PTRACE_SINGLESTEP` you can also stop at after each instruction. If you
like to know how many instructions are executed when executing your program
you can simply add a counter.

```c
int counter = 0;
wait(&wait_val);

// the child is finished; wait_val != 1407
while (WIFSTOPPED(wait_val)) {

	// increase instruction counter
	counter++;

	/* Make the child execute another instruction */
	if (ptrace(PTRACE_SINGLESTEP, pid, 0, 0) != 0) {
		perror("ptrace");
	}

	wait(&wait_val);
}
printf("instructions executed: %d\n", counter);
```

It's not only possible to step trough the instructions, once the program is
stopped at some instruction, you can read the registers, including the
instruction pointer.

The registers are read with `ptrace(PTRACE_GETREGS, pid, NULL, &regs)`. The
`regs` variable is a `user_regs_struct`. In this struct the `rip` contains
the instruction pointer (for 64 bit, for 32 bit it's called `eip`).

Once you know the address of the instruction, why not read the actual data
at that address? Sure, why not! That is exactly what `PTRACE_PEEKTEXT` does.
According to `man ptrace`:

> Read a word at the address addr in the tracee's memory, returning the word
> as the result of the ptrace() call.

```c
unsigned long data = ptrace(PTRACE_PEEKTEXT, pid, addr, NULL);
```

Unfortunately the result, something like `c3c74880cd`, didn't say me very
much. Neither did I want to read through the entire Intel documentation to
figure out what it could mean. I needed something like `objdump` which could
disassemble a compiled binary…

udis86
------

After searching the internet and GitHub I found
[udis86](http://udis86.sourceforge.net/). Udis86 is a disassembler Library for
x86 and x86-64. With the help of some examples over the internet I ended up
with something like:

```c
int disas(int pid, unsigned long addr)
{
	ud_t ud_obj;
	unsigned char buff[4];

	if (read_data(pid, addr, buff) == -1) {
		printf("(Can't read)\n");
		return -1;
	}

	ud_init(&ud_obj);
	ud_set_input_buffer(&ud_obj, buff, 32);
	ud_set_mode(&ud_obj, 64);
	ud_set_syntax(&ud_obj, UD_SYN_INTEL);

	// ud_disassemble fills the ud_obj struct with data
	if (ud_disassemble(&ud_obj) != 0) {
		printf("%016lx %-20s %s\n", addr,
		       ud_insn_hex(&ud_obj), ud_insn_asm(&ud_obj));
	}

	return (int)ud_insn_len(&ud_obj);
}
```

With the code above the instructions are printed, which is pretty nice.
If we compile and disassemble the following assembly:

```ams
section .text
    global _start

_start:
   mov     rdx,len
   mov     rcx,msg
   mov     rbx,1
   mov     rax,4
   int     0x80
   mov     rbx,0
   mov     rax,1
   int     0x80

section .data
msg     db      "Hello, world!",0xa
len     equ     $ - msg
```

We get the following output already:

```
00000000004000b0 48ba0e00000000009c66 mov rdx, 0x669c00000000000e
00000000004000ba 48c7c1e4006000       mov rcx, 0x6000e4
00000000004000c1 48c7c301000000       mov rbx, 0x1
00000000004000c8 48c7c004000000       mov rax, 0x4
00000000004000cf cd80                 int 0x80
Hello, world!
00000000004000d1 48c7c300000000       mov rbx, 0x0
00000000004000d8 48c7c001000000       mov rax, 0x1
00000000004000df cd80                 int 0x80
```

Our final goal however was to know the execution time of the program.
Inspecting the `ud_t` struct we find that it has a `mnemonic` field.
This is the perfect candidate to be used in a simple `case` statement, because
all possible values are constants like `UD_Imov`.

For the hello world assembly code, which has only `mov` and `int` instructions
the following code is already enough:

```c
int unsigned lookup_instruction_time(ud_mnemonic_code_t mnemonic)
{
	// See itab.h for all different types of instruction mnemonic constants
	switch (mnemonic) {
	case UD_Imov:		// mov
		return 1;
	case UD_Iint:		// int
		return 2;
	default:
		return 0;
	}

}
```

Of course the values are just made up, because you'd have to correctly measure
or look into the documentation how long the instruction would take.

Suitability
-----------

I think this quite cool that it's possible. It is a combination of dynamic
and static execution time analysis. If you want to know the Worst Case
Execution Time (WCET), static analysis is always more than the actual WCET and
dynamic is always less. This combination would thus maybe be more or less the
correct value.

Unfortunately it's probably not as straightforward with modern hardware to
exactly determine the execution time. Modern hardware has many features (e.g.
piping and caching) to improve the Average Execution Time. This is what 99% of
the users want. In case you're doing such an analysis you are probably not
only interested in the average, but more in the boundaries: the worst or best
cases.

If the program contains enough instructions, it's probably possible to say
something but you can't prove it. When you're building a hard real-time system
that might be necessary though.
