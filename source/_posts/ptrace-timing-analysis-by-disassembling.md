title: Dynamic disassembling instructions with ptrace and udis86 for timing analysis
date: 2013-04-07 20:15:54
tags: [ptrace, udis86]
comments: yes
---

For a University assignment for a course called _Real-Time Systems_ we had to
implement a prototype for timing analysis by tracing instructions.

The idea is that when executing a program, ptrace controls the execution and
stops after each instruction. After this the actual instruction in the memory
can be fetched with `PTRACE_PEEKTEXT`. Knowing which instruction is executed
you could associate this with a certain time, add all times together for each
instruction and you could dynamically determine how long a program would run.


