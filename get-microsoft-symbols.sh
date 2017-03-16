#! /bin/bash

DIR="$(dirname "${BASH_SOURCE[0]}")"
DMP_FILE=$1
SYMBOLS_DIR=$2
WORKING_DIR="/tmp/"

if [ ! -e "${DMP_FILE}" ]
then
	echo "${DMP_FILE} does not exist"
	exit 1
fi

if [ ! -d "${SYMBOLS_DIR}" ]
then
	echo "${SYMBOLS_DIR} does not exist or is not a directory"
	exit 1
fi

STACKWALK="${DIR}/breakpad/src/processor/minidump_stackwalk"
DUMPSYMS="${DIR}/breakpad/src/tools/windows/binaries/dump_syms.exe"

MISSING_SYMBOLS=$(${STACKWALK} ${DMP_FILE} ${SYMBOLS_DIR} 2> /dev/null | \
	grep "WARNING: No symbols" | \
	sed -e 's/.*WARNING: No symbols, \([^,]*\), \([^)]*\))/\1 \2/' | \
	sort | uniq)

while read -r LINE
do
	ARRAY=($LINE)
	PDB=${ARRAY[0]}
	ID=${ARRAY[1]}

	if [[ -z "${ID}" || -z "${PDB}" ]]
	then
		continue
	fi

	LIB="${PDB%.*}"
	SYM_FILE="${SYMBOLS_DIR}/${PDB}/${ID}/${LIB}.sym"
	
	if [ -e ${SYM_FILE} ]
	then
		echo "WARNING: ${SYM_FILE} already exists, not overwriting"
		continue
	fi

	echo -n ${PDB}
	CAB="${LIB}.pd_"
	echo -n "."
	curl -s -f -A "Microsoft-Symbol-Server/6.3.0.0" \
		"http://msdl.microsoft.com/download/symbols/${PDB}/${ID}/${CAB}" \
		-o "${WORKING_DIR}/${CAB}"
	if [ ! -e "${WORKING_DIR}/${CAB}" ]
	then
		echo " failed to download"
		continue
	fi

	echo "."
	EXTRACTED_FILES=$(cabextract -d ${WORKING_DIR} "${WORKING_DIR}/${CAB}" | \
		grep "  extracting" | sed -e 's/\s*extracting\s*//')
	while read -r EXTRACTED_FILE
	do
		SYM_DIR=$(dirname ${SYM_FILE})
		mkdir -m 775 -p ${SYM_DIR}

		EXT="${EXTRACTED_FILE##*.}"
		case ${EXT} in
		"pdb")
			wine ${DUMPSYMS} "${EXTRACTED_FILE}" 2> /dev/null > ${SYM_FILE}
			chmod 664 ${SYM_FILE}
			echo "  converted: ${SYM_FILE}"
			;;
		"sym")
			cp "${EXTRACTED_FILE}" ${SYM_FILE}
			chmod 664 ${SYM_FILE}
			echo "  copied: ${SYM_FILE}"
			;;
		esac
		rm ${EXTRACTED_FILE}
	done <<< "${EXTRACTED_FILES}"
	rm "${WORKING_DIR}/${CAB}"
done <<< "${MISSING_SYMBOLS}"
